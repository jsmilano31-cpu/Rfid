document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('[data-mailbox]');
    if (!root) return;
    const find = selector => root.querySelector(selector);
    const icons = {
        back:'<path d="m14 6-6 6 6 6M8 12h13"/>', next:'<path d="m9 6 6 6-6 6"/>', previous:'<path d="m15 6-6 6 6 6"/>',
        refresh:'<path d="M20 7v5h-5M4 17v-5h5"/><path d="M6 7a7 7 0 0 1 12-1l2 6M4 12l2 6a7 7 0 0 0 12-1"/>',
        star:'<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9L12 3Z"/>',
        trash:'<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
        restore:'<path d="M3 10h12M7 6l-4 4 4 4M15 10a6 6 0 1 1-5 9"/>',
        read:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        unread:'<path d="M19 12v8H3V7l8 6 4-3M3 7l8-5 4 2"/><circle cx="19" cy="5" r="3"/>',
        reply:'<path d="m9 5-6 6 6 6M3 11h10a7 7 0 0 1 7 7v2"/>',
        forward:'<path d="m15 5 6 6-6 6M21 11H11a7 7 0 0 0-7 7v2"/>',
    };
    const svg = icon => `<svg viewBox="0 0 24 24" aria-hidden="true">${icons[icon]}</svg>`;
    const node = (tag, cls, text) => { const el = document.createElement(tag); if (cls) el.className = cls; if (text !== undefined) el.textContent = text; return el; };
    const button = (action, label, id, icon = action) => {
        const el = node('button', 'mail-icon'); el.type = 'button'; el.dataset.mailAction = action;
        if (id) el.dataset.id = id; el.title = label; el.setAttribute('aria-label', label); el.innerHTML = svg(icon); return el;
    };
    const list = find('[data-mail-list]'), detail = find('[data-mail-detail]'), listView = find('[data-mail-list-view]');
    const feedback = find('[data-mail-feedback]'), selectAll = find('[data-mail-select-all]');
    const compose = find('[data-mail-compose]'), composeSection = find('.mail-compose');
    const lightbox=document.querySelector('[data-mail-image-dialog]');
    let previewFiles=[], previewIndex=0, previewFocus=null;
    const showImage = () => {
        const file=previewFiles[previewIndex];
        lightbox.querySelector('[data-mail-image-title]').textContent=file.name;
        const image=lightbox.querySelector('[data-mail-image]'), error=lightbox.querySelector('[data-mail-image-error]');
        image.hidden=false;error.hidden=true;image.alt=file.name;
        image.onerror=()=>{image.hidden=true;error.hidden=false;}; image.src=file.preview_url;
        const download=lightbox.querySelector('[data-mail-image-download]');download.href=file.url||file.preview_url;download.download=file.name;
        lightbox.querySelector('[data-mail-image-count]').textContent=`${previewIndex+1} of ${previewFiles.length}`;
        lightbox.querySelector('[data-mail-image-previous]').disabled=previewIndex===0;
        lightbox.querySelector('[data-mail-image-next]').disabled=previewIndex===previewFiles.length-1;
    };
    const openPreview = (files,index) => {
        const chosen=files[index];previewFiles=files.filter(file=>file.is_image);previewIndex=previewFiles.indexOf(chosen);
        if(previewIndex<0) return;previewFocus=document.activeElement;showImage();lightbox.showModal();
    };
    lightbox.querySelector('[data-mail-image-close]').addEventListener('click',()=>lightbox.close());
    lightbox.addEventListener('click',event=>{if(event.target===lightbox) lightbox.close();});
    lightbox.addEventListener('close',()=>{lightbox.querySelector('[data-mail-image]').removeAttribute('src');if(previewFocus?.isConnected)previewFocus.focus();});
    ['previous','next'].forEach(direction=>lightbox.querySelector(`[data-mail-image-${direction}]`).addEventListener('click',()=>{previewIndex+=direction==='next'?1:-1;showImage();}));
    lightbox.addEventListener('keydown',event=>{if(['ArrowLeft','ArrowRight'].includes(event.key)) {event.preventDefault();previewIndex=Math.max(0,Math.min(previewFiles.length-1,previewIndex+(event.key==='ArrowRight'?1:-1)));showImage();}});
    const composer=compose ? new window.CampusMessageComposer(compose,openPreview) : null;
    let folder = 'inbox', page = 1, query = '', items = [], pages = 1, selected = new Set(), activeMessage = null;
    let listController, listVersion = 0, detailVersion = 0, writing = false, searchTimer;
    const announce = (text, error = false) => { feedback.textContent = text; feedback.classList.toggle('is-error', error); };
    const date = (message, full = false) => (full ? message.sent_at_display : message.sent_at_date) || message.created_at || '—';
    const api = async (params = {}, data = null, signal = null) => {
        const url = new URL(root.dataset.endpoint, location.origin); Object.entries(params).forEach(([key,value]) => url.searchParams.set(key,value));
        const controller = new AbortController();
        const abort = () => controller.abort(); signal?.addEventListener('abort', abort, {once:true});
        if (signal?.aborted) controller.abort();
        const timeout = setTimeout(abort, data ? 120000 : 20000);
        try {
            const response = await fetch(url, {method:data ? 'POST':'GET', credentials:'same-origin', headers:{Accept:'application/json'}, body:data, signal:controller.signal});
            if (response.status === 401) throw new Error('Your session expired. Sign in again.');
            const payload = await response.json();
            if (!response.ok || !payload.ok) throw new Error(payload.message || 'Unable to load messages. Please try again.');
            return payload.data;
        } catch (error) {
            if (error.name === 'AbortError' && !signal?.aborted) throw new Error('The request timed out. Try again.');
            throw error;
        } finally { clearTimeout(timeout); signal?.removeEventListener('abort', abort); }
    };
    const post = async (action, ids) => {
        const data = new FormData(); data.set('_token',root.dataset.token); data.set('action',action); data.set('ids',JSON.stringify(ids));
        return api({},data);
    };
    const updateSelection = () => {
        const available = items.filter(item => folder !== 'sent').map(item => String(item.id));
        selectAll.disabled = available.length === 0;
        selectAll.checked = available.length > 0 && selected.size === available.length;
        selectAll.indeterminate = selected.size > 0 && selected.size < available.length;
        find('[data-mail-selected]').textContent = selected.size ? `${selected.size} selected` : 'Select';
        const bulk = find('[data-mail-bulk]'); bulk.replaceChildren(); bulk.hidden = selected.size === 0;
        if (selected.size) {
            if (folder === 'trash') bulk.append(button('restore','Restore selected messages'));
            else ['read','unread','star','trash'].forEach(action => bulk.append(button(action,({read:'Mark selected as read',unread:'Mark selected as unread',star:'Star selected',trash:'Move selected to Trash'})[action])));
        }
        list.querySelectorAll('.mail-row').forEach(row => row.classList.toggle('is-selected', selected.has(row.dataset.id)));
    };
    const renderList = data => {
        items = data.items; page = data.page; pages = data.pages; selected.clear(); list.replaceChildren();
        Object.entries(data.counts).forEach(([key,value]) => { const count = find(`[data-mail-count="${key}"]`); if (count) count.textContent = Number(value) ? value : ''; });
        items.forEach(item => {
            const id = String(item.id), sent = folder === 'sent';
            const row = node('div', `mail-row${!sent && !item.read_at ? ' is-unread':''}`); row.dataset.id = id;
            if (!sent) {
                const check = node('input'); check.type = 'checkbox'; check.dataset.mailSelect = id; check.setAttribute('aria-label',`Select ${item.subject}`); row.append(check);
                const star = button(item.starred_at ? 'unstar':'star',item.starred_at ? 'Remove star':'Star message',id,'star');
                star.classList.toggle('is-starred', Boolean(item.starred_at)); star.setAttribute('aria-pressed',String(Boolean(item.starred_at))); row.append(star);
            }
            const link = node('a','mail-row-link'); const url = new URL(location.href); url.searchParams.set('message_id',id); link.href = url.href; link.dataset.mailOpen = id;
            link.setAttribute('aria-label',`${!sent && !item.read_at ? 'Unread: ':''}${item.subject}, ${sent ? 'to '+item.recipient_count+' recipients':item.sender_name}, ${date(item,true)}`);
            link.append(node('span','mail-sender',sent ? `To ${item.recipient_count} recipient${Number(item.recipient_count)===1 ? '':'s'}`:item.sender_name));
            const summary = node('span','mail-summary'); summary.append(node('span','mail-subject',item.subject));
            if (item.is_announcement == 1) summary.append(node('span','mail-announcement','Announcement'));
            if (Number(item.attachment_count)) summary.append(node('span','mail-attachment-count',` ? ${item.attachment_count} file${Number(item.attachment_count)===1?'':'s'}`));
            summary.append(node('span','mail-preview',` — ${item.preview.replace(/\s+/g,' ')}`)); link.append(summary);
            const time = node('time','mail-date',date(item,true)); time.dateTime = item.sent_at || item.created_at.replace(' ','T'); time.title = date(item,true); link.append(time); row.append(link);
            if (!sent) {
                const actions = node('div','mail-row-actions');
                actions.append(button(folder === 'trash' ? 'restore':'trash',folder === 'trash' ? 'Restore message':'Move to Trash',id));
                if (folder !== 'trash') actions.prepend(button(item.read_at ? 'unread':'read',item.read_at ? 'Mark as unread':'Mark as read',id));
                row.append(actions);
            }
            list.append(row);
        });
        if (!items.length) {
            const empty = node('div','mail-empty'); empty.innerHTML = svg('read');
            empty.append(node('h2','',query ? 'No matching messages':({inbox:'Your inbox is clear',starred:'No starred messages',sent:'No sent messages',trash:'Trash is empty'})[folder]),node('p','muted',query ? 'Try a different name, subject, or phrase.':'New messages will appear here.'));
            list.append(empty);
        }
        find('[data-mail-page-info]').textContent = data.total ? `${(page-1)*20+1}–${Math.min(page*20,data.total)} of ${data.total} messages`:'0 messages';
        find('[data-mail-previous]').disabled = page <= 1; find('[data-mail-next]').disabled = page >= pages; updateSelection();
    };
    const load = async () => {
        if (!list) return;
        listController?.abort(); listController = new AbortController(); const version = ++listVersion;
        list.setAttribute('aria-busy','true');
        try { const data = await api({folder,q:query,page},null,listController.signal); if (version === listVersion) renderList(data); }
        catch (error) {
            if (version !== listVersion || error.name === 'AbortError') return;
            items = []; selected.clear(); updateSelection(); find('[data-mail-previous]').disabled = find('[data-mail-next]').disabled = true; list.replaceChildren(node('p','mail-empty',error.message)); const retry = button('retry','Retry loading messages',null,'refresh'); retry.append(node('span','','Retry')); list.append(retry); announce(error.message,true);
        } finally { if (version === listVersion) list.setAttribute('aria-busy','false'); }
    };
    const setUrl = (id, push = true) => {
        const url = new URL(location.href); id ? url.searchParams.set('message_id',id) : url.searchParams.delete('message_id');
        if (url.href !== location.href) history[push ? 'pushState':'replaceState']({},'',url);
    };
    const back = (push = true) => {
        detailVersion++; activeMessage = null; listView.hidden = false; detail.hidden = true; setUrl(null,push);
        find(`[data-mail-folder="${folder}"]`).focus();
    };
    const renderDetail = message => {
        detail.replaceChildren();
        const toolbar = node('div','mail-detail-toolbar'); toolbar.append(button('back','Back to messages',null,'back'));
        toolbar.append(node('span','muted',message.deleted_at ? 'Trash':(message.can_manage ? 'Received message':'Sent message')));
        if (message.can_manage) {
            const star = button(message.starred_at ? 'unstar':'star',message.starred_at ? 'Remove star':'Star message',message.id,'star');
            star.classList.toggle('is-starred',Boolean(message.starred_at)); star.setAttribute('aria-pressed',String(Boolean(message.starred_at))); toolbar.append(star);
            toolbar.append(button(message.read_at ? 'unread':'read',message.read_at ? 'Mark as unread':'Mark as read',message.id));
            toolbar.append(button(message.deleted_at ? 'restore':'trash',message.deleted_at ? 'Restore message':'Move to Trash',message.id));
        }
        detail.append(toolbar);
        const content = node('div','mail-detail-content');
        const title = node('h2','mail-detail-subject',message.subject); title.tabIndex = -1; content.append(title);
        if (message.is_announcement == 1) content.append(node('span','mail-announcement','Announcement'));
        const header = node('div','mail-message-header');
        const avatar = node('span','mail-avatar',message.sender_name.trim().slice(0,1).toLocaleUpperCase()); avatar.setAttribute('aria-hidden','true'); header.append(avatar);
        const identity = node('div','mail-message-identity'); const sender = node('a','',message.sender_name); sender.href = message.sender_url; identity.append(sender);
        identity.append(node('span','muted',message.can_manage ? (message.recipient_kind === 'cc' ? 'CC: me':'To: me'):`To: ${message.recipient_count} recipient${Number(message.recipient_count)===1 ? '':'s'}`)); header.append(identity);
        const sentTime = node('time','mail-message-time',date(message,true)); sentTime.dateTime = message.sent_at || message.created_at.replace(' ','T'); header.append(sentTime); content.append(header);
        if (message.targets?.length) {
            const addresses = node('details','mail-address-details'); addresses.append(node('summary','','Recipient details'));
            ['to','cc'].forEach(kind => {
                const targets = message.targets.filter(target => target.kind === kind); if (!targets.length) return;
                const line = node('div','mail-address-display'); line.append(node('strong','',kind === 'to' ? 'To':'CC'));
                const chips = node('div','mail-recipient-chips');
                targets.forEach(target => { const chip = node('span','mail-recipient-chip',target.label); chip.title = ({user:'Individual user',section:'Section',role:'Role',all:'All active users'})[target.type]; chips.append(chip); });
                line.append(chips); addresses.append(line);
            }); content.append(addresses);
        }
        const body = node('div','mail-message-body',message.body);
        if (message.body_html !== null && message.body_html !== undefined) {
            body.classList.add('is-rich'); body.innerHTML = message.body_html;
            body.querySelectorAll('a').forEach(link => { link.target='_blank';link.rel='noopener noreferrer'; });
        }
        content.append(body);
        if (message.attachments?.length) {
            const section = node('section','mail-received-attachments'); section.append(node('h3','',`${message.attachments.length} attachment${message.attachments.length===1?'':'s'}`));
            const files = node('div','mail-attachments');
            message.attachments.forEach((file,index) => {
                const card=node('div','mail-attachment-card');
                if (file.is_image) {
                    const preview=node('button','mail-attachment-preview');preview.type='button';preview.setAttribute('aria-label',`Preview ${file.name}`);
                    const image=node('img');image.src=file.preview_url;image.alt=file.name;image.loading='lazy';preview.append(image);preview.addEventListener('click',()=>openPreview(message.attachments,index));card.append(preview);
                } else { const glyph=node('span','mail-file-glyph');glyph.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 3h9l5 5v13H5V3Zm9 0v6h5M8 13h8m-8 4h6"/></svg>';card.append(glyph); }
                const meta=node('div','mail-attachment-meta');meta.append(node('strong','',file.name),node('small','muted',file.size>=1048576 ? `${(file.size/1048576).toFixed(1)} MB`:`${Math.max(1,Math.ceil(file.size/1024))} KB`));card.append(meta);
                const download=node('a','mail-icon');download.href=file.url;download.download=file.name;download.title=`Download ${file.name}`;download.setAttribute('aria-label',`Download ${file.name}`);download.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 17v4h16v-4"/></svg>';card.append(download);files.append(card);
            }); section.append(files);content.append(section);
        }
        if (compose && !message.deleted_at) {
            const actions = node('div','mail-message-replies');
            if (Number(message.sender_id) !== Number(root.dataset.userId)) { const reply = button('reply','Reply to sender',message.id,'reply'); reply.append(node('span','','Reply')); actions.append(reply); }
            const forward = button('forward','Forward message',message.id,'forward'); forward.append(node('span','','Forward')); actions.append(forward); content.append(actions);
        }
        detail.append(content); title.focus();
    };
    const openMessage = async (id, push = true) => {
        const version = ++detailVersion; activeMessage = null; listView.hidden = true; detail.hidden = false; detail.setAttribute('aria-busy','true'); detail.replaceChildren(node('p','mail-empty','Opening message…')); setUrl(id,push);
        try {
            const message = await api({message_id:id}); if (version !== detailVersion) return;
            if (message.can_manage && !message.read_at && !message.deleted_at) {
                await post('read',[id]); if (version !== detailVersion) return; message.read_at = 'read'; load();
            }
            activeMessage = message; renderDetail(message);
        } catch (error) {
            if (version !== detailVersion) return; detail.replaceChildren(button('back','Back to messages',null,'back'),node('p','mail-empty',error.message)); announce(error.message,true);
        } finally { if (version === detailVersion) detail.setAttribute('aria-busy','false'); }
    };
    const openCompose = (mode = null) => {
        if (!composer) return;
        composeSection.hidden = false;
        if (mode && activeMessage) composer.prefill(mode,activeMessage,date(activeMessage,true));
        composeSection.scrollIntoView({block:'nearest'});
        mode ? composer.editor.focus() : compose.elements.subject.focus();
    };
    root.addEventListener('click', async event => {
        const link = event.target.closest('[data-mail-open]');
        if (link && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey && event.button === 0) { event.preventDefault(); openMessage(link.dataset.mailOpen); return; }
        const folderButton = event.target.closest('[data-mail-folder]');
        if (folderButton) {
            back(); folder = folderButton.dataset.mailFolder; page = 1; selected.clear();
            root.querySelectorAll('[data-mail-folder]').forEach(button => button.toggleAttribute('aria-current',button === folderButton)); folderButton.setAttribute('aria-current','page');
            find('[data-mail-trash-note]').hidden = folder !== 'trash'; find('[data-mail-search]').value = query = ''; load(); return;
        }
        if (event.target.closest('[data-mail-compose-open]')) { openCompose(); return; }
        if (event.target.closest('[data-mail-compose-close]')) { composeSection.hidden = true; find('[data-mail-compose-open]').focus(); return; }
        const actionButton = event.target.closest('[data-mail-action]'); if (!actionButton) return;
        const action = actionButton.dataset.mailAction;
        if (action === 'back') { back(); return; }
        if (action === 'reply' || action === 'forward') { openCompose(action); return; }
        if (action === 'retry') { load(); return; }
        if (writing) return;
        const ids = actionButton.dataset.id ? [actionButton.dataset.id]:[...selected];
        writing = true; root.querySelectorAll('[data-mail-action]').forEach(button => button.disabled = true);
        const currentId = activeMessage?.id;
        try {
            await post(action,ids);
            announce(({trash:'Moved to Trash. Other recipients keep their copies.',restore:'Message restored to Inbox.',read:'Marked as read.',unread:'Marked as unread.',star:'Message starred.',unstar:'Star removed.'})[action]);
            if (currentId && String(activeMessage?.id) === String(currentId) && ids.includes(String(currentId))) {
                if (['trash','restore','unread'].includes(action)) back();
                else { const version = detailVersion, message = await api({message_id:currentId}); if (version === detailVersion) { activeMessage = message; renderDetail(message); } }
            }
            await load();
        } catch (error) { announce(error.message,true); }
        finally { writing = false; root.querySelectorAll('[data-mail-action]').forEach(button => button.disabled = false); }
    });
    root.addEventListener('change', event => {
        if (event.target.matches('[data-mail-select]')) { const id = event.target.dataset.mailSelect; event.target.checked ? selected.add(id):selected.delete(id); updateSelection(); }
    });
    selectAll?.addEventListener('change', () => { selected = new Set(selectAll.checked ? items.map(item => String(item.id)):[]); list.querySelectorAll('[data-mail-select]').forEach(check => check.checked = selectAll.checked); updateSelection(); });
    find('[data-mail-search]')?.addEventListener('input', event => { clearTimeout(searchTimer); query = event.target.value.trim(); page = 1; listController?.abort(); searchTimer = setTimeout(load,250); });
    const refresh = find('[data-mail-refresh]');
    if (refresh) { refresh.innerHTML = svg('refresh'); refresh.addEventListener('click',load); }
    ['previous','next'].forEach(direction => { const control = find(`[data-mail-${direction}]`); if (!control) return; control.innerHTML = svg(direction); control.addEventListener('click',()=>{ page += direction === 'next' ? 1:-1; load(); }); });
    root.addEventListener('keydown',event => {
        if (event.key === 'Escape') { if (composeSection && !composeSection.hidden) { composeSection.hidden = true; find('[data-mail-compose-open]').focus(); } else if (detail && !detail.hidden) back(); }
        if (event.target.matches('[data-mail-open]') && ['ArrowDown','ArrowUp'].includes(event.key)) {
            event.preventDefault(); const links = [...list.querySelectorAll('[data-mail-open]')]; links[Math.max(0,Math.min(links.length-1,links.indexOf(event.target)+(event.key==='ArrowDown'?1:-1)))].focus();
        }
    });
    compose?.addEventListener('submit',async event => {
        if (event.defaultPrevented) return; event.preventDefault(); if (!composer || composer.sending) return;
        const data = composer.payload(); if (!data) return;
        composer.setSending(true); composer.message('Sending message and attachments?');
        try {
            await api({},data); composer.reset(); composeSection.hidden = true; announce('Message sent.'); find('[data-mail-compose-open]').focus(); load();
        } catch (error) { composer.message(error.message); }
        finally { composer.setSending(false); }
    });
    const openHash = () => { if (location.hash.includes('compose')) openCompose(); else if (location.hash.includes('sent')) find('[data-mail-folder="sent"]')?.click(); };
    window.addEventListener('hashchange',openHash);
    if (list) window.addEventListener('popstate',()=>{ const id = new URL(location.href).searchParams.get('message_id'); id ? openMessage(id,false):back(false); });
    load(); const initialId = new URL(location.href).searchParams.get('message_id'); if (initialId && list) openMessage(initialId,false); openHash();
    if (!list && composer) openCompose();
});
