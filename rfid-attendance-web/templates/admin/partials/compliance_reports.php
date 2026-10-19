<?php if ($complianceTab === 'classes'): ?><section class="card section-scroll-target" id="compliance-class-attendance" data-section-target>
                <h2>Class attendance summary</h2>
                <div class="table-wrap"><table><thead><tr><th>Course</th><th>Section</th><th>Status</th><th>Total</th></tr></thead><tbody><?php if (!$attendanceSummary): ?><tr><td colspan="4" class="empty-table-cell">No attendance records are available.</td></tr><?php endif; ?>
                    <?php foreach ($attendanceSummary as $row): ?><tr><td><?= e($row['course_code']) ?></td><td><?= e($row['section_name']) ?></td><td><span class="badge badge-<?= e(strtolower($row['status'])) ?>"><?= e($row['status']) ?></span></td><td><?= e($row['total']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </section><?php endif; ?>

<?php if ($complianceTab === 'events'): ?><section class="card section section-scroll-target" id="compliance-event-attendance" data-section-target>
                <h2>Event attendance summary</h2>
                <div class="table-wrap"><table><thead><tr><th>Event</th><th>Date</th><th>Status</th><th>Total</th></tr></thead><tbody><?php if (!$eventSummary): ?><tr><td colspan="4" class="empty-table-cell">No attendance records are available.</td></tr><?php endif; ?>
                    <?php foreach ($eventSummary as $row): ?><tr><td><?= e($row['title']) ?></td><td><?= e($row['event_date']) ?></td><td><span class="badge badge-<?= e(strtolower($row['status'])) ?>"><?= e($row['status']) ?></span></td><td><?= e($row['total']) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </section><?php endif; ?>
