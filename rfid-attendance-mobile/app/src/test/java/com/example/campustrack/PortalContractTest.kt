package com.example.campustrack

import com.example.campustrack.data.*
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import java.time.DayOfWeek

class PortalContractTest {
    private fun payload() = JSONObject("""{
      "student":{"first_name":"Test","middle_name":null,"last_name":"Student","school_id":"TEST","email":"test@example.invalid","program":null,"section":null,"department":null,"roles":["Student"],"profile_image_url":null,"rfid":null,"is_profile_public":false},
      "courses":[{"id":7,"course_code":"CS101","title":"Computing","teachers":[],"section":"A","semester":"First","prelim":null,"midterm":81.25,"final":90.75,"overall":86.95,"grade_status":"draft","meetings":[{"day_of_week":"Monday","start_time":"08:00:00","end_time":"09:30:00","room":"Lab"},{"day_of_week":"Thursday","start_time":"13:00:00","end_time":"14:30:00","room":null}]}],
      "attendance":[{"id":4,"class_offering_id":99,"attendance_date":"2026-09-01","time_in":null,"time_out":null,"status":"Absent","source":"system","title":"Previous subject","course_code":"OLD101"}],
      "attendance_counts":[{"status":"Present","total":600},{"status":"Absent","total":100}],
      "activities":[{"id":"event-1","title":"Campus day","kind":"Event","date":"2026-09-01","end_date":"2026-09-03","start_time":"08:00:00","end_time":"17:00:00","venue":"Hall","description":"Welcome","attendance":"Incomplete","status":"completed","required":true,"image_url":null,"images":[]}],
      "messages":[{"id":1,"sender":"Office","subject":"Update","body":"Hello","created_at":"2026-09-01 12:00:00","read_at":"2026-09-01 13:00:00"}],
      "compliance":{"total_deficiencies":42},"subject_compliance":[{"class_offering_id":7,"class_deficiencies":3}],
      "grading":{"has_prelim":0,"has_midterm":1,"has_final":1,"passing_grade":75},"attendance_window_days":90
    }""")

    @Test fun preservesServerGradesAndMeetingDetails() {
        val data = parsePortal(payload())
        val course = data.courses.single()
        assertEquals("7", course.id)
        assertEquals("CS101 LEC", course.code)
        assertNull(course.prelim)
        assertEquals(86.95, course.overall!!, 0.001)
        assertEquals(DayOfWeek.THURSDAY, course.meetings[1].day)
        assertEquals("1:00 PM – 2:30 PM", course.meetings[1].time)
        assertEquals(listOf("Midterm", "Final"), data.enabledTerms)
    }

    @Test fun handlesHistoricalSubjectsAndAuthoritativeCounts() {
        val data = parsePortal(payload())
        assertEquals("OLD101 LEC", data.course("99").code)
        assertEquals(100, data.count(AttendanceStatus.Absent))
        assertEquals(85, data.attendancePercent)
        assertEquals(42, data.compliance["total_deficiencies"])
        assertEquals("Incomplete", data.activities.single().attendance)
        assertTrue(data.messages.single().read)
        assertEquals("Not assigned", data.student.program)
        assertFalse(data.student.isProfilePublic)
    }
}
