package com.example.campustrack

import com.example.campustrack.data.*
import org.junit.Assert.*
import org.junit.Test
import java.time.*

class StudentCalendarTest {
    @Test fun campusDateIgnoresDeviceTimezone() {
        val data = sampleStudentData().copy(timezone = "Asia/Taipei")
        val clock = Clock.fixed(Instant.parse("2026-09-05T17:00:00Z"), ZoneId.of("America/Los_Angeles"))
        assertEquals(LocalDate.parse("2026-09-06"), data.today(clock))
    }

    @Test fun suspensionCoversBothBoundaryDatesAndDaysOff() {
        val course = sampleStudentData().courses.first().copy(
            timeOff = listOf(TimeOff(LocalDate.parse("2026-09-07"), LocalDate.parse("2026-09-09"), "Approved leave", "Teacher")),
            daysOff = listOf(DayOfWeek.SUNDAY)
        )
        assertTrue(course.notice(LocalDate.parse("2026-09-07"))!!.contains("suspended"))
        assertTrue(course.notice(LocalDate.parse("2026-09-09"))!!.contains("Approved leave"))
        assertNull(course.notice(LocalDate.parse("2026-09-10")))
        assertEquals("Department day off", course.notice(LocalDate.parse("2026-09-06")))
    }

    @Test fun unlimitedHistoryIsLabelledClearly() {
        assertEquals("All recorded history", sampleStudentData().copy(windowDays = 0).historyLabel)
    }
}
