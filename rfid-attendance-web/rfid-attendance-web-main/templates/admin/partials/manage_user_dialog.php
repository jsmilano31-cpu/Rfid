<dialog class="app-dialog app-dialog-large" data-user-dialog data-endpoint="<?= e(url('/admin/user.php')) ?>" aria-labelledby="manage-user-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div>
                <p class="eyebrow">Account management</p>
                <h2 id="manage-user-title" data-user-dialog-title>Manage user</h2>
                <p class="muted" data-user-dialog-subtitle>Loading account details…</p>
            </div>
            <button type="button" class="dialog-close" data-user-dialog-close aria-label="Close user management dialog">
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg>
            </button>
        </header>

        <div class="app-dialog-body">
            <div class="dialog-loading" data-user-dialog-loading role="status">Loading user details…</div>
            <div class="alert alert-danger" data-user-dialog-error role="alert" hidden></div>

            <div class="manage-user-content" data-user-dialog-content hidden>
                <form method="post" class="user-manage-section" data-user-manage-form>
                    <?= \App\Core\Csrf::field() ?>
                    <input type="hidden" name="action" value="update_user">
                    <input type="hidden" name="user_id">
                    <div class="user-manage-section-heading">
                        <div><h3>Account details</h3><p>Update identity, access role, academic assignment, and account status.</p></div>
                    </div>
                    <div class="form-grid">
                        <label class="field"><span>School ID *</span><input name="school_id" maxlength="40" required></label>
                        <label class="field"><span>Email *</span><input type="email" name="email" maxlength="190" required></label>
                        <label class="field"><span>First name *</span><input name="first_name" maxlength="80" required></label>
                        <label class="field"><span>Middle name</span><input name="middle_name" maxlength="80"></label>
                        <label class="field"><span>Last name *</span><input name="last_name" maxlength="80" required></label>
                        <label class="field"><span>Primary role *</span><select name="role_id" required><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option><?php endforeach; ?></select><small class="field-help">Controls the user’s default workspace.</small></label>
                        <label class="field"><span>Additional roles</span><select name="role_ids[]" multiple data-searchable><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option><?php endforeach; ?></select><small class="field-help">Permissions from every assigned role are combined.</small></label>
                        <label class="field"><span>Department code</span><select name="department_id"><option value="">None</option><?php foreach ($departments as $department): ?><option value="<?= e($department['id']) ?>"><?= e($department['code']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Program</span><select name="program_id"><option value="">None</option><?php foreach ($programs as $program): ?><option value="<?= e($program['id']) ?>"><?= e($program['name']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Section</span><select name="section_id"><option value="">None</option><?php foreach ($sections as $section): ?><option value="<?= e($section['id']) ?>"><?= e($section['name']) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Status *</span><select name="status" data-no-search required><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
                    </div>
                    <div class="ajax-form-footer"><span class="ajax-form-status" data-ajax-status aria-live="polite"></span><button type="submit" class="button button-primary">Save account</button></div>
                </form>

                <div class="user-manage-secondary-grid">
                    <form method="post" enctype="multipart/form-data" class="user-manage-section" data-user-manage-form data-profile-image-form>
                        <?= \App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="update_user_profile_image">
                        <input type="hidden" name="user_id">
                        <div class="user-manage-section-heading"><div><h3>Profile picture</h3><p>Upload a clear JPG, PNG, or WebP image up to 2 MB.</p></div></div>
                        <div class="profile-image-editor"><img src="<?= e(url('/assets/images/default-avatar.svg')) ?>" alt="Current profile picture" data-user-profile-image><label class="field"><span>Choose image *</span><input type="file" name="profile_image" accept="image/jpeg,image/png,image/webp" required data-image-preview-input></label></div>
                        <div class="ajax-form-footer"><span class="ajax-form-status" data-ajax-status aria-live="polite"></span><button type="submit" class="button button-primary">Upload picture</button></div>
                    </form>

                    <?php if (\App\Core\Auth::can('manage_rfid')): ?><form method="post" class="user-manage-section" data-user-manage-form>
                        <?= \App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="update_user_rfid">
                        <input type="hidden" name="user_id">
                        <div class="user-manage-section-heading"><div><h3>RFID card</h3><p>Assign, replace, or remove this user’s card.</p></div></div>
                        <label class="field"><span>RFID UID</span><input id="manage-user-rfid" name="rfid_uid" maxlength="20" pattern="[A-Fa-f0-9]{8,20}" placeholder="Leave blank to remove assignment"></label>
                        <div class="ajax-form-footer"><span class="ajax-form-status" data-ajax-status aria-live="polite"></span><div class="table-actions"><button type="submit" class="button button-primary">Save RFID</button></div></div>
                    </form><?php endif; ?>

                    <form method="post" class="user-manage-section" data-user-manage-form>
                        <?= \App\Core\Csrf::field() ?>
                        <input type="hidden" name="action" value="reset_user_password">
                        <input type="hidden" name="user_id">
                        <div class="user-manage-section-heading"><div><h3>Password</h3><p>Set a new temporary or permanent password.</p></div></div>
                        <div class="stack">
                            <label class="field"><span>New password *</span><input type="password" name="new_password" minlength="8" autocomplete="new-password" required></label>
                            <label class="field"><span>Confirm password *</span><input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></label>
                        </div>
                        <div class="ajax-form-footer"><span class="ajax-form-status" data-ajax-status aria-live="polite"></span><button type="submit" class="button button-primary">Update password</button></div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</dialog>
