<?php $settings = $settings ?? \App\Services\UserPreferenceService::DEFAULTS; ?>
<section class="settings-page">
    <div class="page-heading"><div><p class="eyebrow">Your experience</p><h1>Settings</h1><p>Choose how CampusTrack looks, opens, and keeps you informed.</p></div></div>
    <?php $sectionNavigationLabel = 'Settings sections'; $sectionNavigation = [['id'=>'settings-general','label'=>'General'],['id'=>'settings-notifications','label'=>'Notifications'],['id'=>'settings-appearance','label'=>'Appearance'],['id'=>'settings-privacy','label'=>'Privacy & account']]; ?>
    <form method="post" class="settings-layout section-navigation-layout" data-settings-form data-section-navigation>
        <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="update_settings">
        <?php require BASE_PATH . '/templates/partials/section_navigation.php'; ?>
        <div class="settings-content section-navigation-content">
            <article class="card settings-section section-scroll-target" id="settings-general" data-section-target><p class="eyebrow">General</p><h2>Starting point</h2><p class="muted">Open the area you use most after signing in.</p>
                <label class="field"><span>Default start page</span><select name="start_page">
                    <?php foreach (['home'=>'Home / Dashboard','attendance'=>'Attendance','schedule'=>'Schedule','messages'=>'Messages','more'=>'More / Workspace'] as $value=>$label): ?><option value="<?= e($value) ?>" <?= $settings['start_page']===$value?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?>
                </select></label>
                <label class="settings-toggle"><span><strong>Compact layout</strong><small>Fit more information on larger screens.</small></span><input type="hidden" name="compact_mode" value="0"><input type="checkbox" name="compact_mode" value="1" <?= $settings['compact_mode']?'checked':'' ?>><i aria-hidden="true"></i></label>
            </article>
            <article class="card settings-section section-scroll-target" id="settings-notifications" data-section-target><p class="eyebrow">Notifications</p><h2>What reaches you</h2><p class="muted">System and security messages always remain available.</p>
                <?php foreach ([['push_enabled','Push notifications','Receive alerts when CampusTrack is closed.'],['notify_attendance','Attendance alerts','Late occurrences, absences, and corrections.'],['notify_events','Events','Upcoming events and schedule changes.'],['notify_recollections','Recollections','Recollection assignments and reminders.'],['notify_messages','Messages','New campus and direct messages.']] as [$key,$label,$help]): ?>
                <label class="settings-toggle"><span><strong><?= e($label) ?></strong><small><?= e($help) ?></small></span><input type="hidden" name="<?= e($key) ?>" value="0"><input type="checkbox" name="<?= e($key) ?>" value="1" <?= $settings[$key]?'checked':'' ?>><i aria-hidden="true"></i></label>
                <?php endforeach; ?>
            </article>
            <article class="card settings-section section-scroll-target" id="settings-appearance" data-section-target><p class="eyebrow">Appearance & customization</p><h2>Make it yours</h2>
                <fieldset class="setting-choice"><legend>Display mode</legend><div class="segmented-control"><?php foreach (['system'=>'System','light'=>'Light','dark'=>'Dark'] as $value=>$label): ?><label><input type="radio" name="theme_mode" value="<?= e($value) ?>" <?= $settings['theme_mode']===$value?'checked':'' ?>><span><?= e($label) ?></span></label><?php endforeach; ?></div></fieldset>
                <fieldset class="setting-choice"><legend>Accent color</legend><div class="theme-swatches"><?php foreach (['blue'=>'Blue','green'=>'Green','purple'=>'Purple','orange'=>'Orange'] as $value=>$label): ?><label class="theme-swatch theme-swatch-<?= e($value) ?>"><input type="radio" name="accent_theme" value="<?= e($value) ?>" <?= $settings['accent_theme']===$value?'checked':'' ?>><span><i></i><?= e($label) ?></span></label><?php endforeach; ?></div></fieldset>
                <label class="settings-toggle"><span><strong>Reduce motion</strong><small>Minimize transitions and animated effects.</small></span><input type="hidden" name="reduced_motion" value="0"><input type="checkbox" name="reduced_motion" value="1" <?= $settings['reduced_motion']?'checked':'' ?>><i aria-hidden="true"></i></label>
            </article>
            <article class="card settings-section section-scroll-target" id="settings-privacy" data-section-target><p class="eyebrow">Privacy & account</p><h2>Profile visibility</h2>
                <label class="settings-toggle"><span><strong>Public profile</strong><small>Allow campus users to open your profile. Core school functions are unaffected.</small></span><input type="hidden" name="profile_public" value="0"><input type="checkbox" name="profile_public" value="1" <?= (($user['profile_visibility']??'public')==='public')?'checked':'' ?>><i aria-hidden="true"></i></label>
                <a class="button button-secondary" href="<?= e(url(str_starts_with($_SERVER['REQUEST_URI']??'', '/admin')?'/admin/?page=profile':'/index/?page=profile')) ?>">Manage account and password</a>
            </article>
            <div class="settings-save"><span data-settings-state>Your changes apply across web and mobile.</span><button class="button button-primary">Save settings</button></div>
        </div>
    </form>
</section>
