(() => {
    const make = (tag, cls, text) => { const el=document.createElement(tag); if(cls) el.className=cls; if(text!==undefined) el.textContent=text; return el; };
    const icon = path => `<svg viewBox="0 0 24 24" aria-hidden="true"><path d="${path}"/></svg>`;
    const size = bytes => bytes>=1048576 ? `${(bytes/1048576).toFixed(1)} MB` : `${Math.max(1,Math.ceil(bytes/1024))} KB`;
    window.CampusMessageComposer = class {
        constructor(form, preview) {
            this.form=form; this.preview=preview; this.targets=[]; this.files=[]; this.forwarded=[]; this.sending=false;
            this.limits=JSON.parse(form.dataset.uploadLimits); this.status=form.querySelector('[data-mail-compose-status]');
            this.editor=new Quill(form.querySelector('[data-mail-editor]'), {
                theme:'snow', placeholder:'Write your message…',
                formats:['bold','italic','underline','strike','header','list','align','blockquote','code-block','link','indent','script','color','background'],
                modules:{toolbar:{container:[[{header:[1,2,3,false]}],['bold','italic','underline','strike'],[{list:'ordered'},{list:'bullet'}],[{indent:'-1'},{indent:'+1'}],[{align:[]}],['blockquote','code-block','link'],[{color:[]},{background:[]}],['clean'],['undo','redo']],handlers:{undo:()=>this.editor.history.undo(),redo:()=>this.editor.history.redo()}},history:{userOnly:true}}
            });
            form.querySelectorAll('.ql-toolbar select').forEach(select=>select.setAttribute('data-no-search',''));
            this.editor.root.setAttribute('role','textbox'); this.editor.root.setAttribute('aria-label','Message body'); this.editor.root.setAttribute('aria-multiline','true');
            const labels={header:'Heading style',bold:'Bold',italic:'Italic',underline:'Underline',strike:'Strikethrough',list:'List',indent:'Indent',align:'Text alignment',blockquote:'Quote', 'code-block':'Code block',link:'Insert link',color:'Text color',background:'Highlight color',clean:'Clear formatting',undo:'Undo',redo:'Redo'};
            form.querySelectorAll('.ql-toolbar button').forEach(button=>{
                button.type='button'; const command=[...button.classList].find(name=>name.startsWith('ql-'))?.slice(3);
                const label=labels[command]+(button.value ? ` (${button.value})`:''); button.title=label; button.setAttribute('aria-label',label);
                if(command==='undo'||command==='redo') button.innerHTML=icon(command==='undo'?'M3 10h12a6 6 0 0 1 0 12M3 10l5-5M3 10l5 5':'M21 10H9a6 6 0 0 0 0 12M21 10l-5-5M21 10l-5 5');
            });
            form.querySelectorAll('.ql-picker').forEach(picker=>{const command=[...picker.classList].find(name=>Object.hasOwn(labels,name.slice(3))); if(command) picker.querySelector('.ql-picker-label')?.setAttribute('aria-label',labels[command.slice(3)]);});
            this.editor.on('text-change',()=>this.syncBody());
            form.addEventListener('remote-user:selected',event=>{
                this.addTarget('user',event.detail.user.id,event.detail.user.label); form.dispatchEvent(new Event('ajax:reset-lookups'));
            });
            form.querySelector('[data-mail-recipient-add]').addEventListener('click',()=>{
                const type=form.elements.recipient_type.value;
                if(type==='all') this.addTarget('all',null,'All active users');
                else if(type==='user') {
                    const id=form.querySelector('[data-remote-user-id]').value;
                    id ? this.addTarget('user',id,form.querySelector('[data-remote-user-input]').value):this.message('Choose a person from the search results.');
                } else {
                    const select=form.querySelector(`[data-recipient-group="${type}"] select`);
                    select.value ? this.addTarget(type,select.value,select.selectedOptions[0].textContent):this.message(`Select a ${type} first.`);
                }
            });
            form.elements.recipient_type.addEventListener('change',()=>form.querySelector('[data-mail-all-hint]').hidden=form.elements.recipient_type.value!=='all');
            form.querySelector('[data-mail-cc-toggle]').addEventListener('click',()=>{
                const row=form.querySelector('[data-mail-cc-row]'); row.hidden=false; const kind=form.querySelector('[data-mail-recipient-kind]');
                kind._choicesInstance?.setChoiceByValue('cc'); kind.value='cc'; this.renderTargets(); form.querySelector('[data-remote-user-input]').focus();
            });
            form.addEventListener('click',event=>{
                const remove=event.target.closest('[data-mail-remove-target]'); if(remove&&!this.sending) {this.targets.splice(Number(remove.dataset.mailRemoveTarget),1);this.renderTargets();}
                const fileRemove=event.target.closest('[data-mail-remove-file]');
                if(fileRemove&&!this.sending) {
                    const index=Number(fileRemove.dataset.mailRemoveFile);
                    if(index<this.files.length) {URL.revokeObjectURL(this.files[index].preview_url);this.files.splice(index,1);} else this.forwarded.splice(index-this.files.length,1);
                    this.renderFiles();
                }
                const image=event.target.closest('[data-mail-staged-image]'); if(image) this.preview(this.allFiles(),Number(image.dataset.mailStagedImage));
            });
            const input=form.querySelector('[data-mail-files]'); form.querySelector('[data-mail-attach]').addEventListener('click',()=>input.click());
            input.addEventListener('change',()=>{this.addFiles([...input.files]);input.value='';});
            form.addEventListener('dragover',event=>{if(event.dataTransfer?.types.includes('Files')) {event.preventDefault(); form.classList.add('is-dragging');}});
            form.addEventListener('dragleave',event=>{if(!form.contains(event.relatedTarget)) form.classList.remove('is-dragging');});
            form.addEventListener('drop',event=>{form.classList.remove('is-dragging'); if(event.dataTransfer?.files.length) {event.preventDefault();this.addFiles([...event.dataTransfer.files]);}});
            this.editor.root.addEventListener('paste',event=>{const files=[...(event.clipboardData?.files || [])];if(files.length) {event.preventDefault();event.stopImmediatePropagation();this.addFiles(files);}},true);
            form.querySelector('[data-mail-upload-help]').textContent=`Drag files here · ${size(this.limits.file)} each · ${size(this.limits.total)} total · up to ${this.limits.count} files`;
            this.renderTargets(); this.syncBody();
        }
        message(text) {this.status.textContent=text;}
        addTarget(type,id,label,kind=null) {
            if(this.sending) return; kind ||= this.form.querySelector('[data-mail-recipient-kind]').value;
            const key=target=>target.type===type&&String(target.id)===String(id);
            if(this.targets.some(target=>key(target)&&(target.kind===kind||target.kind==='to'))) {this.message('That recipient is already included.');return;}
            if(this.targets.length>=50) {this.message('Choose up to 50 people or groups.');return;}
            if(kind==='to') this.targets=this.targets.filter(target=>!key(target));
            this.targets.push({kind,type,id,label});this.renderTargets();this.message(`Added ${label} to ${kind==='to'?'To':'CC'}.`);
        }
        renderTargets() {
            ['to','cc'].forEach(kind=>{
                const container=this.form.querySelector(`[data-mail-recipients="${kind}"]`);container.replaceChildren();
                this.targets.forEach((target,index)=>{
                    if(target.kind!==kind) return;
                    const chip=make('span','mail-recipient-chip');chip.append(make('span','mail-recipient-type',({user:'Person',section:'Section',role:'Role',all:'Campus'})[target.type]),make('span','',target.label));
                    const remove=make('button');remove.type='button';remove.dataset.mailRemoveTarget=index;remove.setAttribute('aria-label',`Remove ${target.label} from ${kind==='to'?'To':'CC'}`);remove.innerHTML=icon('m6 6 12 12M18 6 6 18');chip.append(remove);container.append(chip);
                });
                if(!container.childElementCount) container.append(make('span','muted',kind==='to'?'Add people, sections, or roles below':'People or groups receiving a copy'));
            });
            const cc=this.form.querySelector('[data-mail-cc-row]');if(this.targets.some(target=>target.kind==='cc')) cc.hidden=false;
            this.form.querySelector('[data-mail-cc-toggle]').setAttribute('aria-expanded',String(!cc.hidden));
            this.form.elements.recipients_json.value=JSON.stringify(this.targets);
        }
        syncBody() {this.form.elements.body.value=this.editor.getText().trim();this.form.elements.body_html.value=this.editor.getSemanticHTML();}
        allFiles() {return [...this.files,...this.forwarded];}
        addFiles(incoming) {
            if(this.sending) return;
            const extensions=['jpg','jpeg','png','webp','gif','pdf','txt','csv','md','zip','doc','docx','xls','xlsx','ppt','pptx'];
            let total=this.allFiles().reduce((sum,file)=>sum+file.size,0), count=this.allFiles().length;
            const accepted=[], errors=[];
            for(const file of incoming) {
                if(this.files.some(item=>item.file.name===file.name&&item.file.size===file.size&&item.file.lastModified===file.lastModified)) continue;
                if(!extensions.includes(file.name.split('.').pop().toLowerCase())) {errors.push(`${file.name}: unsupported file type.`);continue;}
                if(file.size<1||file.size>this.limits.file) {errors.push(`${file.name}: maximum ${size(this.limits.file)} per file.`);continue;}
                if(count>=this.limits.count||total+file.size>this.limits.total) {errors.push('Attachment count or total size limit reached.');continue;}
                accepted.push({file,name:file.name,size:file.size,is_image:['image/jpeg','image/png','image/webp','image/gif'].includes(file.type),preview_url:URL.createObjectURL(file)});total+=file.size;count++;
            }
            this.files.push(...accepted);this.renderFiles();this.message(errors.join(' ')||`${accepted.length} attachment${accepted.length===1?'':'s'} added.`);
        }
        renderFiles() {
            const container=this.form.querySelector('[data-mail-staged-attachments]');container.replaceChildren();
            this.allFiles().forEach((file,index)=>{
                const card=make('div','mail-attachment-card');
                if(file.is_image) {const preview=make('button','mail-attachment-preview');preview.type='button';preview.dataset.mailStagedImage=index;preview.setAttribute('aria-label',`Preview ${file.name}`);const img=make('img');img.src=file.preview_url;img.alt=file.name;preview.append(img);card.append(preview);}
                else {const glyph=make('span','mail-file-glyph');glyph.innerHTML=icon('M5 3h9l5 5v13H5V3Zm9 0v6h5M8 13h8m-8 4h6');card.append(glyph);}
                const meta=make('div','mail-attachment-meta');meta.append(make('strong','',file.name),make('small','muted',`${size(file.size)}${file.file?'':' · Forwarded'}`));card.append(meta);
                const remove=make('button','mail-icon');remove.type='button';remove.dataset.mailRemoveFile=index;remove.setAttribute('aria-label',`Remove attachment ${file.name}`);remove.innerHTML=icon('m6 6 12 12M18 6 6 18');card.append(remove);container.append(card);
            });
        }
        hasDraft() {return Boolean(this.form.elements.subject.value||this.editor.getText().trim()||this.targets.length||this.allFiles().length);}
        prefill(mode,message,date) {
            if(this.hasDraft()) {this.message('Your existing draft is open. Finish it before starting another message.');return;}
            this.form.elements.subject.value=`${mode==='reply'?'Re:':'Fwd:'} ${message.subject}`.slice(0,180);
            const quote=make('blockquote');quote.append(make('p','',`From: ${message.sender_name} · ${date}\nSubject: ${message.subject}`));
            const body=make('div'); if(message.body_html) body.innerHTML=message.body_html;else {const p=make('p','',message.body);p.style.whiteSpace='pre-wrap';body.append(p);} quote.append(body);
            this.editor.setContents(this.editor.clipboard.convert({html:'<p><br></p>'+quote.outerHTML}));
            if(mode==='reply') this.addTarget('user',message.sender_id,message.sender_name,'to');
            if(mode==='forward') {this.forwarded=message.attachments.map(file=>({...file}));this.renderFiles();}
            this.syncBody();
        }
        payload() {
            this.syncBody();
            if(!this.targets.some(target=>target.kind==='to')) {this.message('Add at least one To recipient.');this.form.querySelector('[data-mail-recipient-add]').focus();return null;}
            if(!this.form.elements.body.value) {this.message('Write a message before sending.');this.editor.focus();return null;}
            const data=new FormData(this.form);data.delete('attachments[]');
            this.files.forEach(item=>data.append('attachments[]',item.file));data.set('forward_attachment_ids',JSON.stringify(this.forwarded.map(file=>file.id)));
            return data;
        }
        setSending(value) {this.sending=value;this.editor.enable(!value);this.form.classList.toggle('is-sending',value);this.form.setAttribute('aria-busy',String(value));this.form.querySelector('[type=submit]').disabled=value;}
        reset() {
            this.files.forEach(file=>URL.revokeObjectURL(file.preview_url));this.targets=[];this.files=[];this.forwarded=[];this.form.reset();this.editor.setText('');this.editor.history.clear();
            this.form.dispatchEvent(new Event('ajax:reset-lookups'));
            this.form.elements.recipient_type._choicesInstance?.setChoiceByValue('user');this.form.elements.recipient_type.value='user';this.form.elements.recipient_type.dispatchEvent(new Event('change'));
            const kind=this.form.querySelector('[data-mail-recipient-kind]');kind._choicesInstance?.setChoiceByValue('to');kind.value='to';
            this.form.querySelector('[data-mail-cc-row]').hidden=true;this.renderTargets();this.renderFiles();this.message('');
        }
    };
})();
