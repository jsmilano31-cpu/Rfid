(() => {
    'use strict';

    const notify = (message, isError = false) => {
        const region = document.querySelector('[data-toast-region]') || document.body;
        const toast = document.createElement('div');
        toast.className = `app-toast${isError ? ' is-error' : ''}`;
        toast.setAttribute('role', isError ? 'alert' : 'status');
        toast.innerHTML = `<span class="app-toast-icon">${isError ? '!' : '✓'}</span><span class="app-toast-copy"><strong>${isError ? 'Error' : 'Success'}</strong><span>${message}</span></span><button class="app-toast-close" type="button" aria-label="Dismiss">×</button>`;
        toast.querySelector('button').addEventListener('click', () => toast.remove());
        region.append(toast);
        setTimeout(() => toast.remove(), isError ? 8000 : 4500);
    };

    const requestJson = async (url, options = {}) => {
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json', ...(options.headers || {}) },
            ...options,
        });
        let payload;
        try {
            payload = await response.json();
        } catch {
            throw new Error('Invalid response from server.');
        }
        if (!response.ok || !payload.ok) {
            throw new Error(payload.message || 'Operation failed.');
        }
        return payload;
    };

    // ==========================================
    // 1. GLOBAL ENTITY NETWORK (CLICK & RIGHT CLICK CONTEXT MENU)
    // ==========================================
    (() => {
        const menu = document.createElement('div');
        menu.className = 'entity-context-menu';
        menu.hidden = true;
        menu.setAttribute('role', 'menu');
        menu.innerHTML = `
            <div class="entity-menu-header">
                <strong data-entity-menu-name>User</strong>
                <span data-entity-menu-sub>Quick actions</span>
            </div>
            <a role="menuitem" class="entity-menu-item" data-entity-action="profile">
                <svg viewBox="0 0 20 20"><path d="M10 9a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm-7 9a7 7 0 1 1 14 0H3Z"/></svg>
                <span>View profile</span>
            </a>
            <a role="menuitem" class="entity-menu-item" data-entity-action="message">
                <svg viewBox="0 0 20 20"><path d="M3 4h14a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Zm0 2 7 4.5L17 6"/></svg>
                <span>Send message</span>
            </a>
            <a role="menuitem" class="entity-menu-item" data-entity-action="compliance">
                <svg viewBox="0 0 20 20"><path d="M4 3h12v14H4zM7 7h6M7 11h6M7 15h3"/></svg>
                <span>View compliance & records</span>
            </a>
            <div class="entity-menu-separator"></div>
            <button type="button" role="menuitem" class="entity-menu-item" data-entity-action="copy-id">
                <svg viewBox="0 0 20 20"><rect x="6" y="6" width="10" height="10" rx="1.5"/><path d="M4 14V4h10"/></svg>
                <span>Copy School ID</span>
            </button>
            <a role="menuitem" class="entity-menu-item" data-entity-action="manage-user" hidden>
                <svg viewBox="0 0 20 20"><circle cx="10" cy="10" r="3"/><path d="M10 2v2m0 12v2m8-8h-2M4 10H2m13.66-5.66-1.42 1.42M5.76 14.24l-1.42 1.42m11.32 0-1.42-1.42M5.76 5.76 4.34 4.34"/></svg>
                <span>Manage user in admin</span>
            </a>
        `;
        document.body.append(menu);

        let activeTarget = null;
        const hide = () => {
            menu.hidden = true;
            activeTarget = null;
        };

        const positionMenu = (x, y) => {
            menu.hidden = false;
            menu.style.left = '0px';
            menu.style.top = '0px';
            const rect = menu.getBoundingClientRect();
            const left = Math.max(10, Math.min(x, window.innerWidth - rect.width - 10));
            const top = Math.max(10, Math.min(y, window.innerHeight - rect.height - 10));
            menu.style.left = `${left}px`;
            menu.style.top = `${top}px`;
        };

        const openMenu = (el, x, y) => {
            activeTarget = el;
            const userId = el.dataset.userId || '';
            const userName = el.dataset.userName || el.textContent.trim();
            const schoolId = el.dataset.userSchoolId || '';
            const isAdmin = document.body.dataset.adminArea === '1';

            menu.querySelector('[data-entity-menu-name]').textContent = userName || 'User';
            const profileLink = menu.querySelector('[data-entity-action="profile"]');
            profileLink.href = userId ? (isAdmin ? `/admin/?page=profile&id=${userId}` : `/index/?page=directory_profile&id=${userId}`) : '#';

            const messageLink = menu.querySelector('[data-entity-action="message"]');
            messageLink.href = userId ? (isAdmin ? `/admin/?page=messages&recipient_id=${userId}` : `/index/?page=messages&recipient_id=${userId}`) : '#';

            const complianceLink = menu.querySelector('[data-entity-action="compliance"]');
            complianceLink.href = schoolId ? `/admin/?page=compliance&q=${encodeURIComponent(schoolId)}` : (userId ? `/admin/?page=compliance&student_id=${userId}` : '#');
            complianceLink.hidden = !isAdmin;

            const manageLink = menu.querySelector('[data-entity-action="manage-user"]');
            manageLink.hidden = !isAdmin || !schoolId;
            if (manageLink) manageLink.href = `/admin/?page=users&q=${encodeURIComponent(schoolId)}`;

            positionMenu(x, y);
        };

        menu.querySelector('[data-entity-action="copy-id"]').addEventListener('click', () => {
            const schoolId = activeTarget?.dataset.userSchoolId || '';
            if (schoolId) {
                navigator.clipboard.writeText(schoolId).then(() => notify(`Copied School ID: ${schoolId}`));
            }
            hide();
        });

        document.addEventListener('contextmenu', (event) => {
            const trigger = event.target.closest('[data-entity-user], .entity-user-trigger');
            if (!trigger) return;
            event.preventDefault();
            openMenu(trigger, event.clientX, event.clientY);
        });

        document.addEventListener('click', (event) => {
            if (event.target.closest('.entity-context-menu')) return;
            if (!menu.hidden) hide();
        });

        window.addEventListener('scroll', hide, { passive: true });
        window.addEventListener('resize', hide, { passive: true });
    })();

    // ==========================================
    // SMART TEXT OVERFLOW MARQUEE HANDLER
    // ==========================================
    (() => {
        const checkMarquee = (el) => {
            const selectedOption = el.options ? el.options[el.selectedIndex] : null;
            const text = selectedOption ? selectedOption.textContent.trim() : (el.textContent || el.value || '').trim();
            if (!text) return;
            if (!el.title && text) el.title = text;
        };

        document.addEventListener('mouseover', (event) => {
            const target = event.target.closest('select, .choices__inner, .marquee-overflow, .student-search-item-info strong');
            if (!target) return;
            checkMarquee(target);
        }, { passive: true });

        document.addEventListener('change', (event) => {
            if (event.target.tagName === 'SELECT') checkMarquee(event.target);
        });

        // Initialize title attributes for all selects
        document.querySelectorAll('select').forEach((sel) => {
            if (sel.options && sel.options[sel.selectedIndex]) {
                sel.title = sel.options[sel.selectedIndex].textContent.trim();
            }
        });
    })();

    // ==========================================
    // 2. ADMIN ATTENDANCE CONTROL CONSOLE
    // ==========================================
    (() => {
        const consoleEl = document.querySelector('[data-admin-attendance-console]');
        if (!consoleEl) return;

        const endpoint = consoleEl.dataset.endpoint || '/attendance.php';
        const searchInput = consoleEl.querySelector('[data-admin-student-search]');
        const searchResults = consoleEl.querySelector('[data-admin-student-results]');
        const studentCard = consoleEl.querySelector('[data-admin-student-card]');
        const subjectsList = consoleEl.querySelector('[data-student-subjects-list]');
        const paginationBox = consoleEl.querySelector('[data-student-subjects-pagination]');
        const pageInfo = consoleEl.querySelector('[data-student-subjects-page-info]');
        const prevBtn = consoleEl.querySelector('[data-student-subjects-prev]');
        const nextBtn = consoleEl.querySelector('[data-student-subjects-next]');

        const form = consoleEl.querySelector('[data-admin-correction-form]');
        const formStudentId = form?.querySelector('[data-form-student-id]');
        const formStudentName = form?.querySelector('[data-form-student-name]');
        const formSchoolId = form?.querySelector('[data-form-school-id]');
        const formOfferingSelect = form?.querySelector('[data-form-offering-select]');
        const formStatus = form?.querySelector('[data-form-status]');
        const formFeedback = form?.querySelector('[data-correction-status]');

        let debounceTimer = null;
        let activeStudentData = null;
        let currentClasses = [];
        let currentSubjectPage = 1;
        const SUBJECTS_PER_PAGE = 3;

        const renderSubjectsPage = (page = 1) => {
            currentSubjectPage = page;
            subjectsList.replaceChildren();

            if (!currentClasses.length) {
                subjectsList.innerHTML = '<p class="muted" style="font-size:12px; margin:0;">No active enrolled classes found for this student.</p>';
                if (paginationBox) paginationBox.hidden = true;
                return;
            }

            const totalPages = Math.ceil(currentClasses.length / SUBJECTS_PER_PAGE);
            const start = (currentSubjectPage - 1) * SUBJECTS_PER_PAGE;
            const end = Math.min(start + SUBJECTS_PER_PAGE, currentClasses.length);
            const pageClasses = currentClasses.slice(start, end);

            pageClasses.forEach((c) => {
                const row = document.createElement('div');
                row.className = 'student-subject-row';
                if (formOfferingSelect && String(formOfferingSelect.value) === String(c.class_offering_id)) {
                    row.classList.add('is-active');
                }
                row.innerHTML = `
                    <div>
                        <strong>${c.course_code}</strong> — ${c.course_title}
                        <span class="muted" style="display:block; font-size:11px;">Section: ${c.section_name} · Teacher: ${c.teacher_names || 'TBA'}</span>
                    </div>
                    <div style="text-align:right; white-space:nowrap; flex-shrink:0;">
                        <span class="badge badge-active" title="Present">${c.present_count}P</span>
                        <span class="badge badge-late" title="Late">${c.late_count}L</span>
                        <span class="badge badge-absent" title="Absent">${c.absent_count}A</span>
                        <span class="badge" title="Excused">${c.excused_count}E</span>
                    </div>
                `;
                row.addEventListener('click', () => {
                    subjectsList.querySelectorAll('.student-subject-row').forEach((r) => r.classList.remove('is-active'));
                    row.classList.add('is-active');
                    if (formOfferingSelect) {
                        formOfferingSelect.value = String(c.class_offering_id);
                    }
                });
                subjectsList.append(row);
            });

            if (paginationBox) {
                if (totalPages > 1) {
                    paginationBox.hidden = false;
                    if (pageInfo) pageInfo.textContent = `Page ${currentSubjectPage} of ${totalPages} (${currentClasses.length} subjects)`;
                    if (prevBtn) prevBtn.disabled = currentSubjectPage <= 1;
                    if (nextBtn) nextBtn.disabled = currentSubjectPage >= totalPages;
                } else {
                    paginationBox.hidden = true;
                }
            }
        };

        if (prevBtn) {
            prevBtn.addEventListener('click', () => {
                if (currentSubjectPage > 1) renderSubjectsPage(currentSubjectPage - 1);
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => {
                const totalPages = Math.ceil(currentClasses.length / SUBJECTS_PER_PAGE);
                if (currentSubjectPage < totalPages) renderSubjectsPage(currentSubjectPage + 1);
            });
        }

        const renderStudentCard = (data) => {
            activeStudentData = data;
            const student = data.student;
            studentCard.hidden = false;

            // 1. Populate Profile Card (Left)
            const avatarEl = studentCard.querySelector('[data-student-avatar]');
            if (avatarEl) avatarEl.src = student.profile_image_path || '/assets/images/default-avatar.svg';

            const nameEl = studentCard.querySelector('[data-student-name]');
            if (nameEl) {
                nameEl.textContent = `${student.first_name} ${student.middle_name ? student.middle_name + ' ' : ''}${student.last_name}`;
                nameEl.dataset.userId = student.id;
                nameEl.dataset.userName = `${student.first_name} ${student.last_name}`;
                nameEl.dataset.userSchoolId = student.school_id;
                nameEl.onclick = () => {
                    window.location.href = `/admin/?page=profile&id=${student.id}`;
                };
            }

            const idDisplay = studentCard.querySelector('[data-student-id-display]');
            if (idDisplay) idDisplay.textContent = student.school_id || '—';

            const deptEl = studentCard.querySelector('[data-student-department]');
            if (deptEl) deptEl.textContent = student.department_code ? `${student.department_code} — ${student.department_name}` : (student.department_name || '—');

            const progEl = studentCard.querySelector('[data-student-program]');
            if (progEl) progEl.textContent = student.program_code ? `${student.program_code} — ${student.program_name}` : (student.program_name || '—');

            const sectEl = studentCard.querySelector('[data-student-section]');
            if (sectEl) sectEl.textContent = student.section_name || 'Unassigned';

            const rfidEl = studentCard.querySelector('[data-student-rfid]');
            if (rfidEl) rfidEl.textContent = student.rfid_uid ? `UID: ${student.rfid_uid}` : 'Not registered';

            const defEl = studentCard.querySelector('[data-student-deficiencies]');
            if (defEl) defEl.textContent = String(data.compliance?.total_deficiencies ?? 0);

            // 2. Render Enrolled Subjects
            currentClasses = data.classes || [];
            renderSubjectsPage(1);

            // 3. Populate Right-Hand Attendance Adjustment Form
            if (formStudentId) formStudentId.value = student.id;
            if (formStudentName) formStudentName.value = `${student.first_name} ${student.last_name}`;
            if (formSchoolId) formSchoolId.value = student.school_id || '—';

            if (formOfferingSelect) {
                formOfferingSelect.replaceChildren();
                const defaultOpt = document.createElement('option');
                defaultOpt.value = '';
                defaultOpt.textContent = currentClasses.length ? 'Select or search subject…' : 'No enrolled subjects';
                formOfferingSelect.appendChild(defaultOpt);

                currentClasses.forEach((c) => {
                    const opt = document.createElement('option');
                    opt.value = String(c.class_offering_id);
                    opt.textContent = `${c.course_code} — ${c.course_title} (${c.section_name})`;
                    formOfferingSelect.appendChild(opt);
                });

                if (currentClasses.length > 0) {
                    formOfferingSelect.value = String(currentClasses[0].class_offering_id);
                    setTimeout(() => {
                        const firstRow = subjectsList.querySelector('.student-subject-row');
                        if (firstRow) firstRow.classList.add('is-active');
                    }, 50);
                }
            }

            // 4. View Profile Button Navigates directly to the student profile page in admin
            const viewProfileBtn = studentCard.querySelector('[data-student-profile-btn]');
            if (viewProfileBtn) {
                viewProfileBtn.onclick = () => {
                    window.location.href = `/admin/?page=profile&id=${student.id}`;
                };
            }
        };

        const selectStudent = async (studentSummary) => {
            searchResults.hidden = true;
            searchInput.value = `${studentSummary.label} (${studentSummary.identifier})`;
            try {
                const payload = await requestJson(`${endpoint}?mode=student_details&student_id=${studentSummary.id}`);
                renderStudentCard(payload);
            } catch (err) {
                notify(err.message, true);
            }
        };

        const searchStudents = async (query) => {
            if (query.length < 2) {
                searchResults.hidden = true;
                return;
            }
            try {
                const res = await fetch(`/lookups/users.php?context=academic_students&q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                });
                const payload = await res.json();
                if (!payload.ok || !payload.items || !payload.items.length) {
                    searchResults.innerHTML = '<div style="padding:10px 14px; font-size:12px; color:var(--muted);">No matching students found.</div>';
                    searchResults.hidden = false;
                    return;
                }
                searchResults.replaceChildren();
                payload.items.forEach((item) => {
                    const row = document.createElement('div');
                    row.className = 'student-search-item';
                    row.innerHTML = `
                        <img src="${item.profile_image_path || '/assets/images/default-avatar.svg'}" alt="">
                        <div class="student-search-item-info">
                            <strong>${item.label}</strong>
                            <span>${item.identifier} · ${[item.program_code, item.section_name].filter(Boolean).join(' · ')}</span>
                        </div>
                    `;
                    row.addEventListener('click', () => selectStudent(item));
                    searchResults.append(row);
                });
                searchResults.hidden = false;
            } catch {
                searchResults.hidden = true;
            }
        };

        searchInput?.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => searchStudents(searchInput.value.trim()), 250);
        });

        // Submit attendance manual correction
        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            formFeedback.textContent = 'Saving correction…';
            const formData = new FormData(form);
            try {
                const payload = await requestJson(endpoint, {
                    method: 'POST',
                    body: formData,
                });
                formFeedback.textContent = '';
                notify(payload.message || 'Attendance correction saved successfully.');
                // Refresh student card
                if (formStudentId?.value) {
                    const refreshed = await requestJson(`${endpoint}?mode=student_details&student_id=${formStudentId.value}`);
                    renderStudentCard(refreshed);
                }
            } catch (err) {
                formFeedback.textContent = '';
                notify(err.message, true);
            }
        });

        // Auto-fill from URL parameters (e.g. ?student_id=123 or ?student_q=1816670 or ?q=1816670)
        const urlParams = new URLSearchParams(window.location.search);
        const initialStudentId = urlParams.get('student_id') || urlParams.get('user_id');
        const initialQuery = urlParams.get('student_q') || urlParams.get('q') || urlParams.get('school_id');
        if (initialStudentId) {
            (async () => {
                try {
                    const payload = await requestJson(`${endpoint}?mode=student_details&student_id=${initialStudentId}`);
                    if (payload && payload.student) {
                        if (searchInput) searchInput.value = `${payload.student.first_name} ${payload.student.last_name} (${payload.student.school_id})`;
                        renderStudentCard(payload);
                        if (window.location.hash === '#admin-attendance-correction') {
                            const sec = document.querySelector('#admin-attendance-correction');
                            if (sec) setTimeout(() => sec.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
                        }
                    }
                } catch {}
            })();
        } else if (initialQuery && searchInput) {
            searchInput.value = initialQuery;
            (async () => {
                try {
                    const res = await fetch(`/lookups/users.php?context=academic_students&q=${encodeURIComponent(initialQuery)}`, {
                        headers: { Accept: 'application/json' },
                    });
                    const payload = await res.json();
                    if (payload.ok && payload.items && payload.items.length) {
                        const match = payload.items.find(i => i.identifier === initialQuery || i.label.toLowerCase() === initialQuery.toLowerCase()) || payload.items[0];
                        await selectStudent(match);
                        if (window.location.hash === '#admin-attendance-correction') {
                            const sec = document.querySelector('#admin-attendance-correction');
                            if (sec) setTimeout(() => sec.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
                        }
                    }
                } catch {}
            })();
        }
    })();

    // ==========================================
    // 2.5. GRADING SYSTEM POLICIES MANAGEMENT
    // ==========================================
    (() => {
        const policyForm = document.querySelector('[data-grading-policy-form]');
        if (!policyForm) return;

        const endpoint = policyForm.dataset.endpoint || '/grades.php';
        const feedback = policyForm.querySelector('[data-grading-policy-feedback]');
        const formulaPreview = policyForm.querySelector('[data-grading-formula-preview]');
        const weightSumEl = policyForm.querySelector('[data-grading-weight-sum]');
        const weightStatusEl = policyForm.querySelector('[data-grading-weight-status]');
        const submitBtn = policyForm.querySelector('[data-save-grading-policy]');

        const prelimToggle = policyForm.querySelector('[data-term-toggle="prelim"]');
        const midtermToggle = policyForm.querySelector('[data-term-toggle="midterm"]');
        const finalToggle = policyForm.querySelector('[data-term-toggle="final"]');

        const prelimWeight = policyForm.querySelector('[data-term-weight="prelim"]');
        const midtermWeight = policyForm.querySelector('[data-term-weight="midterm"]');
        const finalWeight = policyForm.querySelector('[data-term-weight="final"]');

        const updatePolicyUI = () => {
            const hasPrelim = prelimToggle?.checked ?? false;
            const hasMidterm = midtermToggle?.checked ?? true;
            const hasFinal = finalToggle?.checked ?? true;

            const syncCard = (toggle, weightInput, term) => {
                const card = policyForm.querySelector(`[data-term-card="${term}"]`);
                const label = policyForm.querySelector(`[data-term-toggle-label="${term}"]`);
                if (!toggle || !card) return;
                const isChecked = toggle.checked;
                card.classList.toggle('is-enabled', isChecked);
                card.classList.toggle('is-disabled', !isChecked);
                card.style.background = isChecked ? 'var(--surface)' : 'var(--surface-soft)';
                if (label) {
                    label.textContent = isChecked ? 'Enabled' : 'Disabled';
                    label.style.color = isChecked ? 'var(--brand-readable)' : 'var(--muted)';
                }
                if (weightInput) {
                    weightInput.readOnly = !isChecked;
                    weightInput.style.background = isChecked ? 'var(--surface)' : 'var(--surface-soft)';
                    weightInput.style.color = isChecked ? 'inherit' : 'var(--muted)';
                    if (!isChecked) weightInput.value = '0';
                }
            };

            syncCard(prelimToggle, prelimWeight, 'prelim');
            syncCard(midtermToggle, midtermWeight, 'midterm');
            syncCard(finalToggle, finalWeight, 'final');

            const pw = hasPrelim ? (parseFloat(prelimWeight?.value) || 0) : 0;
            const mw = hasMidterm ? (parseFloat(midtermWeight?.value) || 0) : 0;
            const fw = hasFinal ? (parseFloat(finalWeight?.value) || 0) : 0;
            const totalWeight = Math.round((pw + mw + fw) * 100) / 100;

            const parts = [];
            if (hasPrelim && pw > 0) parts.push(`(Prelim × ${pw}%)`);
            if (hasMidterm && mw > 0) parts.push(`(Midterm × ${mw}%)`);
            if (hasFinal && fw > 0) parts.push(`(Endterm × ${fw}%)`);

            if (formulaPreview) {
                formulaPreview.textContent = parts.length ? `Overall = ${parts.join(' + ')}` : 'Overall = No active evaluation terms configured';
            }

            const isValid = Math.abs(totalWeight - 100) < 0.01 && (hasPrelim || hasMidterm || hasFinal);
            if (weightSumEl) {
                weightSumEl.textContent = `Total Weight: ${totalWeight}%`;
                weightSumEl.style.color = isValid ? '#027a48' : '#b42318';
            }
            if (weightStatusEl) {
                weightStatusEl.textContent = isValid ? '(Valid 100% distribution)' : '(Weights of enabled terms must sum to exactly 100%)';
                weightStatusEl.style.color = isValid ? 'var(--muted)' : '#b42318';
            }
            if (submitBtn) {
                submitBtn.disabled = !isValid;
            }
        };

        [prelimToggle, midtermToggle, finalToggle].forEach((t) => t?.addEventListener('change', updatePolicyUI));
        [prelimWeight, midtermWeight, finalWeight].forEach((w) => w?.addEventListener('input', updatePolicyUI));

        policyForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (feedback) feedback.textContent = 'Saving grading policies…';
            const formData = new FormData(policyForm);
            try {
                const payload = await requestJson(endpoint, {
                    method: 'POST',
                    body: formData,
                });
                if (feedback) feedback.textContent = '';
                notify(payload.message || 'Grading system policies updated successfully.');
                updatePolicyUI();
            } catch (err) {
                if (feedback) feedback.textContent = '';
                notify(err.message, true);
            }
        });

        updatePolicyUI();
    })();

    // ==========================================
    // 3. ADMIN GRADE EXPLORER & MANAGEMENT
    // ==========================================
    (() => {
        const gradeManager = document.querySelector('[data-admin-grade-manager]');
        if (!gradeManager) return;

        const endpoint = gradeManager.dataset.endpoint || '/grades.php';
        const searchInput = gradeManager.querySelector('[data-admin-grade-search]');
        const programSelect = gradeManager.querySelector('[data-admin-grade-program]');
        const sectionSelect = gradeManager.querySelector('[data-admin-grade-section]');
        const statusSelect = gradeManager.querySelector('[data-admin-grade-status]');
        const sortSelect = gradeManager.querySelector('[data-admin-grade-sort]');
        const summary = gradeManager.querySelector('[data-admin-grade-summary]');
        const resultsContainer = gradeManager.querySelector('[data-admin-grade-subject-results]');
        const paginationContainer = gradeManager.querySelector('[data-admin-grade-subject-pagination]');
        const loading = gradeManager.querySelector('[data-admin-grade-subject-loading]');

        const rosterBrowser = gradeManager.querySelector('[data-admin-grade-roster-browser]');
        const subjectBrowser = gradeManager.querySelector('[data-admin-grade-subject-browser]');
        const rosterTbody = gradeManager.querySelector('[data-admin-grade-roster-results]');
        const rosterSubjectName = gradeManager.querySelector('[data-admin-grade-subject-name]');
        const rosterSubjectDetail = gradeManager.querySelector('[data-admin-grade-subject-detail]');
        const rosterBackBtn = gradeManager.querySelector('[data-admin-grade-back]');
        const studentRosterSearch = gradeManager.querySelector('[data-admin-grade-student-search]');
        const rosterPagination = gradeManager.querySelector('[data-admin-grade-roster-pagination]');
        const rosterLoading = gradeManager.querySelector('[data-admin-grade-roster-loading]');

        const editDialog = document.querySelector('#admin-grade-edit-dialog');
        const editForm = editDialog?.querySelector('[data-admin-grade-edit-form]');

        let viewMode = 'cards';
        let currentOfferingId = 0;
        let searchTimer = null;
        let studentLookupTimer = null;

        const formatScore = (score) => (score !== null && score !== undefined && score !== '' ? Number(score).toFixed(2) : '—');

        const loadSubjects = async (page = 1) => {
            if (loading) loading.hidden = false;
            const query = searchInput?.value.trim() || '';
            const programId = programSelect?.value || '';
            const sectionId = sectionSelect?.value || '';
            const status = statusSelect?.value || '';
            const sort = sortSelect?.value || '';

            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('mode', 'subjects');
            url.searchParams.set('page', String(page));
            if (query) url.searchParams.set('q', query);
            if (programId) url.searchParams.set('program_id', programId);
            if (sectionId) url.searchParams.set('section_id', sectionId);
            if (status) url.searchParams.set('status', status);
            if (sort) url.searchParams.set('sort', sort);

            try {
                const payload = await requestJson(url.toString());
                if (loading) loading.hidden = true;
                renderSubjects(payload);
            } catch (err) {
                if (loading) loading.hidden = true;
                notify(err.message, true);
            }
        };

        const renderSubjects = (payload) => {
            const items = payload.subjects || [];
            const meta = payload.pagination || { total: 0, page: 1, pages: 1 };
            if (summary) {
                summary.textContent = `${meta.total} ${meta.total === 1 ? 'subject' : 'subjects'} found${meta.pages > 1 ? ` · Page ${meta.page} of ${meta.pages}` : ''}`;
            }

            resultsContainer.replaceChildren();
            if (!items.length) {
                resultsContainer.innerHTML = '<div class="card empty-state" style="padding:28px; text-align:center;"><p class="muted">No subjects match the selected filters.</p></div>';
                paginationContainer.replaceChildren();
                return;
            }

            if (viewMode === 'cards') {
                const grid = document.createElement('div');
                grid.className = 'grid grid-3';
                items.forEach((item) => {
                    const card = document.createElement('article');
                    card.className = 'card grade-subject-card';
                    card.style.cursor = 'pointer';
                    card.innerHTML = `
                        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                            <span class="badge">${item.section_name}</span>
                            <span class="badge ${item.finalized_count > 0 && item.finalized_count === item.student_count ? 'badge-active' : 'badge-draft'}">
                                ${item.finalized_count}/${item.student_count} Finalized
                            </span>
                        </div>
                        <h3 style="margin:0 0 4px; font-size:16px;"><strong>${item.course_code}</strong> — ${item.course_title}</h3>
                        <p class="muted" style="font-size:12px; margin:0 0 10px;">Teacher: ${item.teacher_names || 'Unassigned'} · Room: ${item.room_code || 'TBA'}</p>
                        <div style="display:flex; justify-content:space-between; font-size:12px; border-top:1px solid var(--line); padding-top:8px;">
                            <span>Class avg: <strong>${item.class_average ? Number(item.class_average).toFixed(2) : '—'}</strong></span>
                            <span>${item.graded_count}/${item.student_count} graded</span>
                        </div>
                    `;
                    card.addEventListener('click', () => openSubjectRoster(item.id, `${item.course_code} — ${item.course_title}`, `${item.section_name} · ${item.teacher_names || 'Unassigned'}`));
                    grid.append(card);
                });
                resultsContainer.append(grid);
            } else {
                const tableWrap = document.createElement('div');
                tableWrap.className = 'table-wrap';
                tableWrap.innerHTML = `
                    <table data-no-datatable>
                        <thead><tr><th>Course</th><th>Title</th><th>Section</th><th>Teacher</th><th>Students</th><th>Graded</th><th>Finalized</th><th>Class Avg</th><th>Action</th></tr></thead>
                        <tbody></tbody>
                    </table>
                `;
                const tbody = tableWrap.querySelector('tbody');
                items.forEach((item) => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td><strong>${item.course_code}</strong></td>
                        <td>${item.course_title}</td>
                        <td>${item.section_name}</td>
                        <td>${item.teacher_names || '—'}</td>
                        <td>${item.student_count}</td>
                        <td>${item.graded_count}</td>
                        <td><span class="badge ${item.finalized_count === item.student_count && item.student_count > 0 ? 'badge-active' : 'badge-draft'}">${item.finalized_count}/${item.student_count}</span></td>
                        <td><strong>${item.class_average ? Number(item.class_average).toFixed(2) : '—'}</strong></td>
                        <td><button type="button" class="button button-secondary button-small">Open roster</button></td>
                    `;
                    tr.querySelector('button').addEventListener('click', () => openSubjectRoster(item.id, `${item.course_code} — ${item.course_title}`, `${item.section_name} · ${item.teacher_names || 'Unassigned'}`));
                    tbody.append(tr);
                });
                resultsContainer.append(tableWrap);
            }

            // Pagination
            paginationContainer.replaceChildren();
            if (meta.pages > 1) {
                for (let p = Math.max(1, meta.page - 2); p <= Math.min(meta.pages, meta.page + 2); p++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = p === meta.page ? 'is-active' : '';
                    btn.textContent = String(p);
                    btn.addEventListener('click', () => loadSubjects(p));
                    paginationContainer.append(btn);
                }
            }
        };

        const openSubjectRoster = async (offeringId, title, detail, page = 1) => {
            currentOfferingId = offeringId;
            subjectBrowser.hidden = true;
            rosterBrowser.hidden = false;
            rosterSubjectName.textContent = title;
            rosterSubjectDetail.textContent = detail;
            loadRoster(offeringId, page);
        };

        const loadRoster = async (offeringId, page = 1) => {
            if (rosterLoading) rosterLoading.hidden = false;
            const query = studentRosterSearch?.value.trim() || '';
            const url = new URL(endpoint, window.location.origin);
            url.searchParams.set('mode', 'roster');
            url.searchParams.set('offering_id', String(offeringId));
            url.searchParams.set('page', String(page));
            if (query) url.searchParams.set('q', query);

            try {
                const payload = await requestJson(url.toString());
                if (rosterLoading) rosterLoading.hidden = true;
                renderRoster(payload);
            } catch (err) {
                if (rosterLoading) rosterLoading.hidden = true;
                notify(err.message, true);
            }
        };

        const renderRoster = (payload) => {
            const students = payload.students || [];
            const meta = payload.pagination || { total: 0, page: 1, pages: 1 };
            rosterTbody.replaceChildren();

            if (!students.length) {
                rosterTbody.innerHTML = '<tr><td colspan="7" class="empty-state">No students found for this subject roster.</td></tr>';
                rosterPagination.replaceChildren();
                return;
            }

            students.forEach((s) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>
                        <a href="/admin/?page=profile&id=${s.student_id}" class="entity-user-trigger" data-entity-user data-user-id="${s.student_id}" data-user-name="${s.student_name}" data-user-school-id="${s.school_id}" data-user-role="Student">
                            <strong>${s.student_name}</strong>
                        </a>
                        <br><span class="muted">${s.school_id}</span>
                    </td>
                    <td>${formatScore(s.prelim)}</td>
                    <td>${formatScore(s.midterm)}</td>
                    <td>${formatScore(s.final)}</td>
                    <td><strong>${formatScore(s.overall)}</strong></td>
                    <td><span class="badge badge-${s.status === 'finalized' ? 'active' : 'draft'}">${s.status || 'unentered'}</span></td>
                    <td><button type="button" class="button button-secondary button-small" data-edit-student-grade>Edit grade</button></td>
                `;
                tr.querySelector('[data-edit-student-grade]').addEventListener('click', () => openGradeDialog(s));
                rosterTbody.append(tr);
            });

            // Roster Pagination
            rosterPagination.replaceChildren();
            if (meta.pages > 1) {
                for (let p = Math.max(1, meta.page - 2); p <= Math.min(meta.pages, meta.page + 2); p++) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = p === meta.page ? 'is-active' : '';
                    btn.textContent = String(p);
                    btn.addEventListener('click', () => loadRoster(currentOfferingId, p));
                    rosterPagination.append(btn);
                }
            }
        };

        let activeGradingSettings = {
            has_prelim: 0,
            has_midterm: 1,
            has_final: 1,
            prelim_weight: 0,
            midterm_weight: 40,
            final_weight: 60,
            passing_grade: 75,
        };

        const computeModalOverall = () => {
            const pw = activeGradingSettings.has_prelim ? activeGradingSettings.prelim_weight / 100 : 0;
            const mw = activeGradingSettings.has_midterm ? activeGradingSettings.midterm_weight / 100 : 0;
            const fw = activeGradingSettings.has_final ? activeGradingSettings.final_weight / 100 : 0;

            const p = parseFloat(editForm.elements.prelim.value);
            const m = parseFloat(editForm.elements.midterm.value);
            const f = parseFloat(editForm.elements.final.value);

            let total = 0;
            let count = 0;
            if (activeGradingSettings.has_prelim && !isNaN(p)) { total += p * pw; count++; }
            if (activeGradingSettings.has_midterm && !isNaN(m)) { total += m * mw; count++; }
            if (activeGradingSettings.has_final && !isNaN(f)) { total += f * fw; count++; }

            if (count > 0) {
                editDialog.querySelector('[data-admin-grade-edit-overall]').textContent = total.toFixed(2);
            } else {
                editDialog.querySelector('[data-admin-grade-edit-overall]').textContent = '—';
            }
        };

        const openGradeDialog = (student) => {
            if (!editDialog || !editForm) return;
            editForm.elements.class_offering_id.value = String(currentOfferingId);
            editForm.elements.student_id.value = String(student.student_id);
            editForm.elements.grade_id.value = student.grade_id ? String(student.grade_id) : '';

            editDialog.querySelector('[data-admin-grade-student-avatar]').src = student.profile_image_path || '/assets/images/default-avatar.svg';
            editDialog.querySelector('[data-admin-grade-student-name]').textContent = student.student_name;
            editDialog.querySelector('[data-admin-grade-student-id]').textContent = student.school_id;
            editDialog.querySelector('[data-admin-grade-edit-status]').textContent = student.status || 'Draft';
            editDialog.querySelector('[data-admin-grade-edit-status]').className = `grade-status badge badge-${student.status === 'finalized' ? 'active' : 'draft'}`;

            const syncTermField = (field, isEnabled) => {
                const label = editForm.elements[field]?.closest('label');
                if (!label) return;
                label.hidden = !isEnabled;
                if (!isEnabled) {
                    editForm.elements[field].removeAttribute('required');
                } else {
                    editForm.elements[field].setAttribute('required', '');
                }
            };
            syncTermField('prelim', activeGradingSettings.has_prelim);
            syncTermField('midterm', activeGradingSettings.has_midterm);
            syncTermField('final', activeGradingSettings.has_final);

            editForm.elements.prelim.value = student.prelim !== null ? student.prelim : '';
            editForm.elements.midterm.value = student.midterm !== null ? student.midterm : '';
            editForm.elements.final.value = student.final !== null ? student.final : '';

            computeModalOverall();

            const finalizeCheck = editDialog.querySelector('[data-admin-grade-finalize-check]');
            finalizeCheck.checked = student.status === 'finalized';

            const reopenBtn = editDialog.querySelector('[data-admin-grade-reopen-btn]');
            if (reopenBtn) {
                reopenBtn.hidden = student.status !== 'finalized' || !student.grade_id;
            }

            if (typeof editDialog.showModal === 'function') editDialog.showModal();
            else editDialog.setAttribute('open', '');
        };

        rosterBackBtn?.addEventListener('click', () => {
            rosterBrowser.hidden = true;
            subjectBrowser.hidden = false;
            loadSubjects();
        });

        studentRosterSearch?.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => loadRoster(currentOfferingId, 1), 250);
        });

        searchInput?.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => loadSubjects(1), 250);
        });

        [programSelect, sectionSelect, statusSelect, sortSelect].forEach((el) => el?.addEventListener('change', () => loadSubjects(1)));

        gradeManager.querySelectorAll('[data-admin-grade-view]').forEach((btn) => {
            btn.addEventListener('click', () => {
                gradeManager.querySelectorAll('[data-admin-grade-view]').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                viewMode = btn.dataset.adminGradeView;
                loadSubjects();
            });
        });

        // Compute overall live in modal
        ['prelim', 'midterm', 'final'].forEach((field) => {
            editForm?.elements[field]?.addEventListener('input', computeModalOverall);
        });

        // Submit Grade modal
        editForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const feedback = editDialog.querySelector('[data-admin-grade-edit-feedback]');
            feedback.textContent = 'Saving grade…';
            try {
                const payload = await requestJson(endpoint, {
                    method: 'POST',
                    body: new FormData(editForm),
                });
                feedback.textContent = '';
                notify(payload.message || 'Grade saved successfully.');
                editDialog.close();
                loadRoster(currentOfferingId);
            } catch (err) {
                feedback.textContent = '';
                notify(err.message, true);
            }
        });

        // Reopen Grade button
        editDialog?.querySelector('[data-admin-grade-reopen-btn]')?.addEventListener('click', async () => {
            const gradeId = editForm.elements.grade_id.value;
            if (!gradeId) return;
            if (!confirm('Are you sure you want to reopen this finalized grade for editing?')) return;
            const formData = new FormData();
            formData.set('action', 'reopen_grade');
            formData.set('grade_id', gradeId);
            formData.set('csrf_token', editForm.querySelector('[name="csrf_token"]').value);
            try {
                const payload = await requestJson(endpoint, { method: 'POST', body: formData });
                notify(payload.message || 'Grade reopened for editing.');
                editDialog.close();
                loadRoster(currentOfferingId);
            } catch (err) {
                notify(err.message, true);
            }
        });

        // ==========================================
        // Student Grade Lookup
        // ==========================================
        const studentLookupInput = document.querySelector('[data-admin-student-grade-lookup]');
        const studentLookupResults = document.querySelector('[data-admin-student-grade-results]');
        const studentLookupSummary = document.querySelector('[data-admin-student-grade-summary]');

        const lookupStudentGrades = async (query) => {
            if (query.length < 2) {
                if (studentLookupResults) studentLookupResults.hidden = true;
                if (studentLookupSummary) studentLookupSummary.textContent = '';
                return;
            }
            if (studentLookupSummary) studentLookupSummary.textContent = 'Searching student records…';

            try {
                // Find student ID first
                const searchRes = await fetch(`/lookups/users.php?context=academic_students&q=${encodeURIComponent(query)}`, {
                    headers: { Accept: 'application/json' },
                });
                const searchPayload = await searchRes.json();
                if (!searchPayload.ok || !searchPayload.items || !searchPayload.items.length) {
                    if (studentLookupSummary) studentLookupSummary.textContent = 'No matching students found.';
                    if (studentLookupResults) studentLookupResults.hidden = true;
                    return;
                }
                const student = searchPayload.items[0];
                const gradePayload = await requestJson(`${endpoint}?mode=student_grades&student_id=${student.id}`);
                renderStudentGradeLookup(gradePayload);
            } catch (err) {
                if (studentLookupSummary) studentLookupSummary.textContent = err.message;
            }
        };

        const renderStudentGradeLookup = (data) => {
            if (!studentLookupResults) return;
            studentLookupResults.hidden = false;
            if (studentLookupSummary) studentLookupSummary.textContent = '';

            const s = data.student;
            studentLookupResults.querySelector('[data-lookup-student-avatar]').src = s.profile_image_path || '/assets/images/default-avatar.svg';
            const nameEl = studentLookupResults.querySelector('[data-lookup-student-name]');
            nameEl.textContent = `${s.first_name} ${s.last_name}`;
            nameEl.dataset.userId = s.id;
            nameEl.dataset.userName = `${s.first_name} ${s.last_name}`;
            nameEl.dataset.userSchoolId = s.school_id;

            studentLookupResults.querySelector('[data-lookup-student-id]').textContent = `${s.school_id} · ${s.role_name}`;
            studentLookupResults.querySelector('[data-lookup-student-details]').textContent = `${s.program_code || '—'} · ${s.section_name || '—'} · ${s.department_code || '—'}`;

            studentLookupResults.querySelector('[data-lookup-student-gpa]').textContent = data.gpa !== null ? Number(data.gpa).toFixed(2) : '—';
            studentLookupResults.querySelector('[data-lookup-student-progress]').textContent = `${data.graded_count} of ${data.total_subjects} subjects completed`;

            const tbody = studentLookupResults.querySelector('[data-lookup-student-grades-tbody]');
            tbody.replaceChildren();

            const grades = data.grades || [];
            if (!grades.length) {
                tbody.innerHTML = '<tr><td colspan="10" class="empty-state">This student is not currently enrolled in any active classes.</td></tr>';
                return;
            }

            grades.forEach((g) => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><strong>${g.course_code}</strong> — ${g.course_title}</td>
                    <td>${g.section_name}</td>
                    <td>${g.teacher_names || '—'}</td>
                    <td>${g.units || 3.0}</td>
                    <td>${formatScore(g.prelim)}</td>
                    <td>${formatScore(g.midterm)}</td>
                    <td>${formatScore(g.final)}</td>
                    <td><strong>${formatScore(g.overall)}</strong></td>
                    <td><span class="badge badge-${g.status === 'finalized' ? 'active' : 'draft'}">${g.status}</span></td>
                    <td><button type="button" class="button button-secondary button-small" data-lookup-edit-grade>Edit</button></td>
                `;
                tr.querySelector('[data-lookup-edit-grade]').addEventListener('click', () => {
                    openSubjectRoster(g.class_offering_id, `${g.course_code} — ${g.course_title}`, g.section_name);
                });
                tbody.append(tr);
            });
        };

        studentLookupInput?.addEventListener('input', () => {
            clearTimeout(studentLookupTimer);
            studentLookupTimer = setTimeout(() => lookupStudentGrades(studentLookupInput.value.trim()), 280);
        });

        // Auto-lookup if student_q or student_id is provided in URL or hash target is #admin-grades-students
        const urlParams = new URLSearchParams(window.location.search);
        const initialStudentQ = urlParams.get('student_q') || urlParams.get('q');
        const initialStudentId = urlParams.get('student_id');

        if (initialStudentId) {
            (async () => {
                try {
                    const gradePayload = await requestJson(`${endpoint}?mode=student_grades&student_id=${initialStudentId}`);
                    if (gradePayload && gradePayload.student) {
                        if (studentLookupInput) studentLookupInput.value = `${gradePayload.student.first_name} ${gradePayload.student.last_name} (${gradePayload.student.school_id})`;
                        renderStudentGradeLookup(gradePayload);
                        const section = document.querySelector('#admin-grades-students');
                        if (section) {
                            setTimeout(() => section.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
                        }
                    }
                } catch {}
            })();
        } else if (initialStudentQ) {
            if (studentLookupInput) studentLookupInput.value = initialStudentQ;
            lookupStudentGrades(initialStudentQ);
            const section = document.querySelector('#admin-grades-students');
            if (section) {
                setTimeout(() => section.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
            }
        } else if (window.location.hash === '#admin-grades-students') {
            const section = document.querySelector('#admin-grades-students');
            if (section) {
                setTimeout(() => section.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
            }
        }

        // Initial load
        loadSubjects();
    })();
})();
