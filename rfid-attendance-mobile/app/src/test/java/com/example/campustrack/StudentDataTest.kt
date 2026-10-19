package com.example.campustrack

import com.example.campustrack.data.*
import org.junit.Assert.*
import org.junit.Test
import java.time.LocalDate

class StudentDataTest {
    @Test fun attendanceOnlyExistsOnScheduledDaysWithUniqueIds() {
        val today = LocalDate.of(2026, 9, 5)
        val data = sampleStudentData(today)
        assertEquals(data.attendance.size, data.attendance.map { it.id }.distinct().size)
        assertTrue(data.attendance.all { it.date.dayOfWeek in data.course(it.courseId).days && it.date.isBefore(today) })
        assertTrue(data.attendance.all { (it.status == AttendanceStatus.Absent || it.status == AttendanceStatus.Excused) == (it.timeIn == null) })
    }

    @Test fun attendanceRateHandlesNoRecordsAndExcludesExcusedSessions() {
        val data = sampleStudentData()
        assertEquals(0, data.copy(attendance = emptyList()).attendancePercent)
        val sample = data.attendance.first()
        val records = listOf(
            sample.copy(status = AttendanceStatus.Present), sample.copy(status = AttendanceStatus.Late),
            sample.copy(status = AttendanceStatus.Absent), sample.copy(status = AttendanceStatus.Excused)
        )
        assertEquals(66, data.copy(attendance = records).attendancePercent)
    }
}
