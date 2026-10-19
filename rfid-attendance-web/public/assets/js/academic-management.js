document.addEventListener('DOMContentLoaded', () => {
    const dialog = document.querySelector('[data-academic-management]');
    if (!dialog) return;
    const form = dialog.querySelector('[data-academic-form]');
    const fields = dialog.querySelector('[data-academic-fields]');
    const loading = dialog.querySelector('[data-academic-loading]');
    const errorBox = dialog.querySelector('[data-academic-error]');
    const feedback = dialog.querySelector('[data-academic-feedback]');
    const title = dialog.querySelector('[data-academic-dialog-title]');
    const subtitle = dialog.querySelector('[data-academic-dialog-subtitle]');
    const labels = { department: 'department', program: 'program', course: 'course', section: 'section', offering: 'class offering' };
    const deleteButton = document.createElement('button');
    deleteButton.type = 'button';
    deleteButton.className = 'button button-danger';
    deleteButton.textContent = 'Delete record';
    deleteButton.dataset.deleteAcademic = '';
    deleteButton.hidden = true;
    form.querySelector('.form-actions')?.prepend(deleteButton);
    let currentRecord = null;
    let teacherTimer;

    const requestJson = async (url, options = {}) => {
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'fetch' }, ...options });
        let payload; try { payload = await response.json(); } catch { throw new Error('The server returned an invalid response.'); }
        if (!response.ok || !payload.ok) throw window.deletionConflictError?.(payload, 'The request could not be completed.') || new Error(payload.message || 'The request could not be completed.');
        return payload;
    };
    const notify = (message, type = 'success') => {
        const toast = document.createElement('div'); toast.className = `app-toast${type === 'success' ? '' : ' is-error'}`; toast.setAttribute('role', type === 'success' ? 'status' : 'alert');
        const icon = document.createElement('span'); icon.className = 'app-toast-icon'; icon.textContent = type === 'success' ? '✓' : '!';
        const copy = document.createElement('span'); copy.className = 'app-toast-copy'; const heading = document.createElement('strong'); heading.textContent = type === 'success' ? 'Saved' : 'Couldn\'t save'; const body = document.createElement('span'); body.textContent = message; copy.append(heading, body);
        const close = document.createElement('button'); close.type = 'button'; close.className = 'app-toast-close'; close.textContent = '×'; close.ariaLabel = 'Dismiss notification'; close.addEventListener('click', () => toast.remove()); toast.append(icon, copy, close);
        (document.querySelector('[data-toast-region]') || document.body).append(toast); window.setTimeout(() => toast.remove(), type === 'success' ? 5000 : 8000);
    };
    const el = (tag, className = '') => { const item = document.createElement(tag); item.className = className; return item; };
    const field = (labelText, control, help = '') => { const label = el('label', 'field'); const caption = document.createElement('span'); caption.textContent = labelText; label.append(caption, control); if (help) { const small = el('small', 'field-help'); small.textContent = help; label.append(small); } return label; };
    const input = (name, value = '', type = 'text', attributes = {}) => { const control = document.createElement('input'); control.name = name; control.type = type; control.value = value ?? ''; Object.entries(attributes).forEach(([key, val]) => { if (val === true) control.setAttribute(key, ''); else if (val !== false) control.setAttribute(key, String(val)); }); return control; };
    const select = (name, rows, value = '', placeholder = '') => { const control = document.createElement('select'); control.name = name; if (placeholder) control.add(new Option(placeholder, '')); rows.forEach((row) => { const option = new Option(row.label, String(row.value)); Object.entries(row.data || {}).forEach(([key, val]) => { option.dataset[key] = val; }); control.add(option); }); control.value = value == null ? '' : String(value); return control; };
    const statusSelect = (value, offering = false) => select('status', (offering ? [['active','Active'],['completed','Completed'],['cancelled','Cancelled']] : [['active','Active'],['inactive','Inactive']]).map(([value,label]) => ({value,label})), value || 'active');
    const grid = (...children) => { const group = el('div', 'form-grid'); children.forEach((child) => group.append(child)); return group; };
    const linkedPair = (labelCode, name, rows, value, detailLabel) => {
        const normalized = rows.map((row) => ({ value: row.id, label: row.code, data: { detail: row.name, ...(row.program_id ? { programId: row.program_id } : {}), ...(row.school_year_id ? { schoolYearId: row.school_year_id } : {}) } }));
        const choice = select(name, normalized, value, `Select ${labelCode.toLowerCase()}`); choice.required = true;
        const detail = input('', '', 'text', { readonly: true }); detail.removeAttribute('name');
        const sync = () => { detail.value = choice.selectedOptions[0]?.dataset.detail || ''; };
        choice.addEventListener('change', sync); sync();
        return { group: grid(field(labelCode, choice), field(detailLabel, detail)), choice, detail };
    };
    const initPicker = (control, isTime) => {
        if (!window.flatpickr) return;
        const initial = control.value; control.type = 'text'; control.autocomplete = 'off';
        window.flatpickr(control, { allowInput: true, altInput: true, altInputClass: `date-time-input ${isTime ? 'time-input' : 'date-input'}`, altFormat: isTime ? 'h:i K' : 'F j, Y', static: true, dateFormat: isTime ? 'H:i' : 'Y-m-d', defaultDate: initial || null, disableMobile: true, enableTime: isTime, minuteIncrement: 5, noCalendar: isTime, time_24hr: false });
    };

    const teacherLookup = (record) => {
        const wrap = el('div', 'remote-user-lookup'); const search = input('', record.teacher_id ? `${record.teacher_school_id} — ${record.teacher_name}` : '', 'search', { autocomplete: 'off', placeholder: 'Type a teacher ID or name', required: true }); search.removeAttribute('name');
        const teacherId = input('teacher_id', record.teacher_id || '', 'hidden'); const results = el('div', 'remote-user-results'); results.hidden = true; const status = el('p', 'field-help'); status.textContent = 'Type at least 2 characters. Results load 20 at a time.';
        const load = async () => { const query = search.value.trim(); teacherId.value = ''; if (query.length < 2) { results.hidden = true; return; } status.textContent = 'Searching…'; try { const url = new URL('/lookups/users.php', window.location.origin); url.searchParams.set('context','teachers'); url.searchParams.set('q',query); const payload=await requestJson(url); results.replaceChildren(); payload.items.forEach((person)=>{const button=el('button','user-lookup-option');button.type='button';const strong=document.createElement('strong');strong.textContent=`${person.identifier} — ${person.label}`;const small=document.createElement('small');small.textContent=person.email;button.append(strong,small);button.addEventListener('click',()=>{teacherId.value=person.id;search.value=`${person.identifier} — ${person.label}`;results.hidden=true;status.textContent='Teacher selected.';});results.append(button);}); results.hidden=false; status.textContent=payload.items.length?'Select a teacher from the results.':'No teachers found.'; } catch(error){status.textContent=error.message;} };
        search.addEventListener('input',()=>{window.clearTimeout(teacherTimer);teacherId.value='';teacherTimer=window.setTimeout(load,250);}); wrap.append(field('Teacher *',search),teacherId,results,status); return wrap;
    };

    const enrollmentManager = (offeringId) => {
        const panel=el('section','academic-enrollment-manager');const heading=el('div','section-heading');const copy=document.createElement('div');const title=document.createElement('h3');title.textContent='Enrollment';const help=el('p','muted');help.textContent='Search the bounded class roster, add irregular students, or change enrollment status.';copy.append(title,help);heading.append(copy);panel.append(heading);
        const controls=grid(field('Search roster',input('','', 'search',{placeholder:'School ID or student name'})),field('Add student',input('','', 'search',{placeholder:'Search all active students'})));const rosterSearch=controls.children[0].querySelector('input');const addSearch=controls.children[1].querySelector('input');rosterSearch.removeAttribute('name');addSearch.removeAttribute('name');panel.append(controls);
        const addResults=el('div','remote-user-results');addResults.hidden=true;panel.append(addResults);const list=el('div','academic-enrollment-list');const footer=el('div','ajax-form-footer');const summary=el('p','field-help');const pagination=el('div','table-actions');const previous=document.createElement('button');previous.type='button';previous.className='button button-small button-secondary';previous.textContent='Previous';const next=previous.cloneNode(true);next.textContent='Next';pagination.append(previous,next);footer.append(summary,pagination);panel.append(list,footer);let page=1;let pages=1;let rosterTimer;let addTimer;
        const updateEnrollment=async(studentId,status)=>{const data=new FormData();data.set('_token',form.elements._token.value);data.set('action','update_offering_enrollment');data.set('offering_id',offeringId);data.set('student_id',studentId);data.set('status',status);const payload=await requestJson(dialog.dataset.endpoint,{method:'POST',body:data});notify(payload.message);await loadRoster();};
        const loadRoster=async()=>{const url=new URL(dialog.dataset.endpoint,location.origin);url.searchParams.set('mode','enrollments');url.searchParams.set('offering_id',offeringId);url.searchParams.set('q',rosterSearch.value.trim());url.searchParams.set('page',page);try{const payload=await requestJson(url);pages=payload.pagination.pages;page=payload.pagination.page;previous.disabled=page<=1;next.disabled=page>=pages;list.replaceChildren();payload.students.forEach(student=>{const row=el('div','academic-enrollment-row');const person=document.createElement('span');const strong=document.createElement('strong');strong.textContent=student.student_name;const small=document.createElement('small');small.textContent=`${student.school_id} · ${student.status}`;person.append(strong,small);const action=document.createElement('button');action.type='button';action.className='icon-action-button';const enrolled=student.status==='enrolled';action.title=enrolled?'Drop student from this offering':'Enroll student in this offering';action.setAttribute('aria-label',action.title);action.innerHTML=enrolled?'<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6 10h8M10 4a3 3 0 1 1 0 6M4 17c.5-3 2.5-5 6-5"/></svg>':'<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M14 10h5M16.5 7.5v5M9 4a3 3 0 1 1 0 6M3 17c.5-3 2.5-5 6-5 1.4 0 2.5.3 3.4.9"/></svg>';action.addEventListener('click',()=>updateEnrollment(student.student_id,enrolled?'dropped':'enrolled').catch(error=>notify(error.message,'error')));row.append(person,action);list.append(row);});summary.textContent=`Showing ${payload.pagination.from}–${payload.pagination.to} of ${payload.pagination.total} enrollment records.`;}catch(error){summary.textContent=error.message;}};
        rosterSearch.addEventListener('input',()=>{clearTimeout(rosterTimer);page=1;rosterTimer=setTimeout(loadRoster,250);});previous.addEventListener('click',()=>{if(page>1){page-=1;loadRoster();}});next.addEventListener('click',()=>{if(page<pages){page+=1;loadRoster();}});
        const searchStudents=async()=>{const query=addSearch.value.trim();if(query.length<2){addResults.hidden=true;return;}const url=new URL('/lookups/users.php',location.origin);url.searchParams.set('context','academic_students');url.searchParams.set('q',query);try{const payload=await requestJson(url);addResults.replaceChildren();payload.items.forEach(person=>{const button=el('button','user-lookup-option');button.type='button';const strong=document.createElement('strong');strong.textContent=`${person.identifier} — ${person.label}`;const small=document.createElement('small');small.textContent=person.email;button.append(strong,small);button.addEventListener('click',async()=>{try{await updateEnrollment(person.id,'enrolled');addSearch.value='';addResults.hidden=true;}catch(error){notify(error.message,'error');}});addResults.append(button);});addResults.hidden=false;}catch(error){notify(error.message,'error');}};addSearch.addEventListener('input',()=>{clearTimeout(addTimer);addTimer=setTimeout(searchStudents,250);});loadRoster();return panel;
    };

    const scheduleEditor = (record, rooms) => {
        const panel=el('section','offering-schedule-builder');const heading=el('div','offering-schedule-heading');const copy=document.createElement('div');const headingTitle=document.createElement('h3');headingTitle.textContent='Meeting schedules';const help=el('p','muted');help.textContent='Add every weekly meeting. Each meeting can use its own day, time, and room.';const add=document.createElement('button');add.type='button';add.className='button button-secondary button-small';add.textContent='+ Add meeting';copy.append(headingTitle,help);heading.append(copy,add);const list=el('div','offering-schedule-list');panel.append(heading,list);
        const roomRows=[{value:'',label:'TBA'},...rooms.map(room=>({value:room.id,label:`${room.code} — ${room.name}`}))];const dayRows=['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'].map(value=>({value,label:value}));
        const reindex=()=>{const rows=[...list.querySelectorAll('[data-offering-schedule-row]')];rows.forEach((row,index)=>{row.querySelectorAll('[data-schedule-field]').forEach(control=>{control.name=`schedules[${index}][${control.dataset.scheduleField}]`;});row.querySelector('[data-remove-offering-schedule]').disabled=rows.length===1;});};
        const append=(schedule={})=>{if(list.children.length>=14)return;const row=el('div','offering-schedule-row');row.dataset.offeringScheduleRow='';row.dataset.scheduleId=String(schedule.id||'');const day=select('',dayRows,schedule.day_of_week||'Monday');day.dataset.scheduleField='day_of_week';day.required=true;const start=input('',String(schedule.start_time||'').slice(0,5),'time',{required:true});start.dataset.scheduleField='start_time';const end=input('',String(schedule.end_time||'').slice(0,5),'time',{required:true});end.dataset.scheduleField='end_time';const room=select('',roomRows,schedule.room_id||'');room.dataset.scheduleField='room_id';room.dataset.searchable='';const remove=document.createElement('button');remove.type='button';remove.className='icon-action-button offering-schedule-remove';remove.dataset.removeOfferingSchedule='';remove.title='Remove meeting schedule';remove.ariaLabel=remove.title;remove.innerHTML='<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 10h12"/></svg>';row.append(field('Day *',day),field('Starts *',start),field('Ends *',end),field('Room',room),remove);list.append(row);initPicker(start,true);initPicker(end,true);window.initializeSearchableSelect?.(room);reindex();};
        add.addEventListener('click',()=>{append();list.lastElementChild?.querySelector('.choices [tabindex="0"],select,input')?.focus();});list.addEventListener('click',event=>{const remove=event.target.closest('[data-remove-offering-schedule]');if(!remove||list.children.length===1)return;const row=remove.closest('[data-offering-schedule-row]');row.querySelectorAll('select').forEach(control=>control._choicesInstance?.destroy());row.querySelectorAll('input').forEach(control=>control._flatpickr?.destroy());row.remove();reindex();});
        const schedules=Array.isArray(record.schedules)&&record.schedules.length?record.schedules:[{}];schedules.forEach(append);return panel;
    };

    const render = ({ entity, record, options }) => {
        currentRecord = record;
        deleteButton.hidden = !record.id;
        fields.replaceChildren(); form.elements.entity.value = entity; form.elements.id.value = record.id || '';
        title.textContent = `${record.id ? 'Edit' : 'Add'} ${labels[entity]}`; subtitle.textContent = record.id ? 'Changes apply anywhere this record is referenced.' : `Create a new ${labels[entity]} without leaving this page.`;
        if (entity === 'department') {
            fields.append(grid(field('Department code *',input('code',record.code,'text',{required:true,maxlength:30})),field('Department name *',input('name',record.name,'text',{required:true,maxlength:150}))),grid(field('Color',select('color_key',['green','yellow','grey','blue','orange','red','maroon'].map(value=>({value,label:value[0].toUpperCase()+value.slice(1)})),record.color_key||'grey')),field('Status',statusSelect(record.status))));
        }
        if (entity === 'program') {
            const department=linkedPair('Department code','department_id',options.departments,record.department_id,'Department name'); fields.append(department.group,grid(field('Program code *',input('code',record.code,'text',{required:true,maxlength:30})),field('Program name *',input('name',record.name,'text',{required:true,maxlength:150}))),field('Status',statusSelect(record.status)));
        }
        if (entity === 'course') {
            const department=linkedPair('Department code','department_id',options.departments,record.department_id,'Department name'); fields.append(department.group,grid(field('Course code *',input('course_code',record.course_code,'text',{required:true,maxlength:30})),field('Course title *',input('title',record.title,'text',{required:true,maxlength:150}))),grid(field('Units *',input('units',record.units,'number',{required:true,min:.5,max:9,step:.5})),field('Status',statusSelect(record.status))));
        }
        if (entity === 'section') {
            const program=linkedPair('Program code','program_id',options.programs,record.program_id,'Program name'); fields.append(program.group,grid(field('Year level *',input('year_level',record.year_level,'number',{required:true,min:1,max:4})),field('Section *',input('section_code',record.section_code,'text',{required:true,maxlength:10,pattern:'[A-Za-z][A-Za-z0-9]{0,9}'}))),field('Status',statusSelect(record.status)));
        }
        if (entity === 'offering') {
            const course=linkedPair('Course code','course_id',options.courses,record.course_id,'Course title'); const program=linkedPair('Program code','program_id',options.programs,record.program_id,'Program name');
            const section=select('section_id',[],record.section_id,'Select a program first'); section.required=true; const allSections=options.sections;
            const refreshSections=()=>{const current=section.value||String(record.section_id||'');section._choicesInstance?.destroy();section._choicesInstance=null;section.replaceChildren(new Option('Select year & section',''));allSections.filter(row=>String(row.program_id)===program.choice.value).forEach(row=>section.add(new Option(`${row.year_level}${row.section_code}`,row.id)));section.value=current;window.initializeSearchableSelect?.(section);section._choicesInstance?.setChoiceByValue(section.value);};program.choice.addEventListener('change',refreshSections);refreshSections();
            const schoolYear=select('school_year_id',options.school_years.map(row=>({value:row.id,label:row.name})),record.school_year_id,'Select school year');schoolYear.required=true;schoolYear.dataset.searchable=''; const semester=select('semester_id',[],record.semester_id,'Select a school year first');semester.required=true;
            const refreshTerms=()=>{const current=semester.value||String(record.semester_id||'');semester._choicesInstance?.destroy();semester._choicesInstance=null;semester.replaceChildren(new Option('Select semester',''));options.semesters.filter(row=>String(row.school_year_id)===schoolYear.value).forEach(row=>semester.add(new Option(row.name,row.id)));semester.value=current;window.initializeSearchableSelect?.(semester);semester._choicesInstance?.setChoiceByValue(semester.value);};schoolYear.addEventListener('change',refreshTerms);refreshTerms();
            const component=window.CampusOfferingComponents.makePicker(record.component_type||'LEC');
            fields.append(course.group,program.group,field('Year & section *',section),grid(field('School year *',schoolYear),field('Semester *',semester)),component,field('Status',statusSelect(record.status,true)),teacherLookup(record),scheduleEditor(record,options.rooms));
            const sync=el('label','event-setting-row');const checkbox=input('sync_section_students','1','checkbox');sync.append(checkbox,document.createTextNode(' Enroll missing active students from the selected section'));fields.append(sync,enrollmentManager(record.id));
        }
        fields.querySelectorAll('[data-offering-components]').forEach(window.CampusOfferingComponents.initialize);
        fields.querySelectorAll('select').forEach((control)=>window.initializeSearchableSelect?.(control));
    };

    const open = async (entity,id) => { form.reset();fields.replaceChildren();feedback.textContent='';errorBox.hidden=true;loading.hidden=false;form.hidden=true;dialog.showModal();try{const url=new URL(dialog.dataset.endpoint,window.location.origin);url.searchParams.set('entity',entity);url.searchParams.set('id',id);const payload=await requestJson(url);render(payload);loading.hidden=true;form.hidden=false;window.requestAnimationFrame(()=>{const focusSchedule=Number(new URLSearchParams(window.location.search).get('focus_schedule'));const row=focusSchedule>0?fields.querySelector(`[data-schedule-id="${focusSchedule}"]`):null;if(row){row.classList.add('is-conflict-focus');row.scrollIntoView({block:'center',behavior:'smooth'});row.querySelector('select,input')?.focus();}else fields.querySelector('input:not([type="hidden"]),select')?.focus();});}catch(error){loading.hidden=true;errorBox.hidden=false;errorBox.textContent=error.message;} };
    document.addEventListener('click',(event)=>{const button=event.target.closest('[data-manage-academic]');if(button)open(button.dataset.manageAcademic,Number(button.dataset.entityId));});
    const requestedEditor = new URLSearchParams(window.location.search);
    if (['department','program','course','section','offering'].includes(requestedEditor.get('manage')) && Number(requestedEditor.get('entity_id')) > 0) {
        open(requestedEditor.get('manage'), Number(requestedEditor.get('entity_id')));
    }

    deleteButton.addEventListener('click', async () => {
        const entity = form.elements.entity.value;
        const id = Number(form.elements.id.value);
        if (!entity || !id || !currentRecord) return;
        const name = entity === 'course' ? `${currentRecord.course_code} — ${currentRecord.title}`
            : entity === 'section' ? currentRecord.name
                : entity === 'offering' ? `${currentRecord.course_code} — ${currentRecord.program_code} ${currentRecord.section_name}`
                    : `${currentRecord.code} — ${currentRecord.name}`;
        const warnings = {
            department: 'Deletion is blocked while programs, courses, or users still reference this department.',
            program: 'Deletion is blocked while sections or users still reference this program.',
            course: 'Deletion is blocked while any class offering uses this course.',
            section: 'Deletion is blocked while users, class offerings, or activities use this section.',
            offering: 'Enrollment rows are removed with an unused offering. Offerings with attendance or grade history cannot be deleted.',
        };
        const confirmed = window.confirmDestructiveAction
            ? await window.confirmDestructiveAction({ title: `Delete ${labels[entity]}?`, name, message: `Are you sure you want to permanently delete ${name}?`, warning: warnings[entity] })
            : window.confirm(`Are you sure you want to permanently delete ${name}?`);
        if (!confirmed) return;
        const data = new FormData();
        data.set('_token', form.elements._token.value);
        data.set('action', 'delete_academic_entity');
        data.set('entity', entity);
        data.set('id', String(id));
        deleteButton.disabled = true;
        feedback.textContent = 'Deleting…';
        try {
            const payload = await requestJson(dialog.dataset.endpoint, { method: 'POST', body: data });
            window.noteBulkDeleted?.(entity, [payload.deleted.id]);
            dialog.close();
            notify(payload.message);
            await refreshSection(entity);
        } catch (error) {
            feedback.textContent = error.message;
            if (!window.showDeletionConflict?.(error.payload)) notify(error.message, 'error');
        } finally {
            deleteButton.disabled = false;
        }
    });

    const initializeCollectionControls=(root)=>{root?.querySelectorAll('[data-collection-controls]').forEach(controls=>{const collection=root.querySelector(controls.dataset.target);if(!collection)return;const search=controls.querySelector('[data-collection-search]');const filters=[...controls.querySelectorAll('[data-collection-filter]')];const refresh=()=>{const query=search?.value.trim().toLocaleLowerCase()||'';collection.querySelectorAll('[data-search-item]').forEach(item=>{const textMatch=!query||item.textContent.toLocaleLowerCase().includes(query);const filterMatch=filters.every(filter=>!filter.value||item.dataset[filter.dataset.collectionFilter]===filter.value);item.hidden=!(textMatch&&filterMatch);});};search?.addEventListener('input',refresh);filters.forEach(filter=>filter.addEventListener('change',refresh));});};
    const syncPageSelects=(doc,selector)=>{const source=doc.querySelector(selector);if(!source)return;document.querySelectorAll(selector).forEach(target=>{if(target.closest('[data-academic-management]'))return;const selected=target.value;target._choicesInstance?.destroy();target._choicesInstance=null;target.replaceChildren(...[...source.options].map(option=>option.cloneNode(true)));target.value=selected;if(window.Choices&&!target.hasAttribute('data-no-search'))target._choicesInstance=new window.Choices(target,{allowHTML:false,itemSelectText:'',searchEnabled:true,searchFloor:1,searchResultLimit:50,shouldSort:false});target._choicesInstance?.setChoiceByValue(selected);target.dispatchEvent(new Event('change',{bubbles:true}));});};
    const refreshSection = async (entity) => { const ids={department:'academics-departments',program:'academics-programs',section:'academics-sections',course:'academics-course-catalog',offering:'academics-class-offerings'};const response=await fetch(window.location.href,{credentials:'same-origin',headers:{Accept:'text/html'}});const html=await response.text();const doc=new DOMParser().parseFromString(html,'text/html');if(entity==='department')syncPageSelects(doc,'select[name="department_id"]');if(entity==='program'){syncPageSelects(doc,'select[name="program_id"]');syncPageSelects(doc,'select[name="offering_program_id"]');}const current=document.getElementById(ids[entity]);const incoming=doc.getElementById(ids[entity]);if(current&&incoming){current.replaceWith(incoming);const replacement=document.getElementById(ids[entity]);initializeCollectionControls(replacement);const table=replacement?.querySelector('table');if(table)window.initializeDataTable?.(table);} };
    form.addEventListener('submit',async(event)=>{event.preventDefault();if(!form.reportValidity()||form.dataset.submitting==='1')return;if(form.elements.entity.value==='offering'&&!form.elements.teacher_id.value){notify('Select a teacher from the search results.','error');return;}form.dataset.submitting='1';const button=event.submitter||form.querySelector('[type="submit"]');button.disabled=true;feedback.textContent='Saving…';try{const payload=await requestJson(dialog.dataset.endpoint,{method:'POST',body:new FormData(form)});dialog.close();notify(payload.message);await refreshSection(payload.entity);document.querySelectorAll('[data-create-offering-form] [name=course_id]').forEach(select=>select.dispatchEvent(new Event('change',{bubbles:true})));}catch(error){feedback.textContent=error.message;notify(error.message,'error');}finally{delete form.dataset.submitting;button.disabled=false;}});
});
