-- One class offering may meet several times each week. Remove only exact legacy
-- duplicates, then protect each offering from receiving two attendance windows on
-- one day (attendance is recorded once per offering and calendar date).

DELETE duplicate_schedule
FROM class_schedules AS duplicate_schedule
JOIN class_schedules AS keeper
  ON keeper.class_offering_id = duplicate_schedule.class_offering_id
 AND keeper.day_of_week = duplicate_schedule.day_of_week
 AND keeper.id < duplicate_schedule.id;

ALTER TABLE class_schedules
    ADD UNIQUE KEY uq_class_schedule_day (class_offering_id, day_of_week),
    ADD INDEX idx_class_schedules_day_time_room (day_of_week, start_time, end_time, room_id);
