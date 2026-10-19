package com.example.campustrack.data

import kotlinx.coroutines.delay
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.format.DateTimeFormatter
import java.util.Locale

data class Student(val name: String, val schoolId: String, val email: String, val program: String, val section: String, val department: String = "", val roles: List<String> = listOf("Student"), val photoUrl: String? = null, val rfidUid: String? = null, val rfidStatus: String? = null, val isProfilePublic: Boolean = true) { val initials: String get() = name.split(" ").filter { it.isNotBlank() }.let { if (it.size > 1) "${it.first().first()}${it.last().first()}" else it.firstOrNull()?.take(2).orEmpty() }.uppercase() }
data class Meeting(val day: DayOfWeek, val time: String, val room: String, val building: String = "", val floor: String = "", val roomCode: String = "")
data class TimeOff(val start: LocalDate, val end: LocalDate, val reason: String, val teacher: String)
data class Teacher(val id: String, val name: String)
data class Course(val id: String, val title: String, val teacher: String, val room: String, val days: List<DayOfWeek>, val time: String, val prelim: Double?, val midterm: Double?, val final: Double?, val overall: Double? = null, val code: String = id, val gradeStatus: String = "Not entered", val meetings: List<Meeting> = emptyList(), val section: String = "", val semester: String = "", val status: String = "active", val teachers: List<Teacher> = emptyList(), val timeOff: List<TimeOff> = emptyList(), val daysOff: List<DayOfWeek> = emptyList(), val gradeUpdatedAt: String = "", val semesterId:String="",val schoolYear:String="",val startsOn:String="",val endsOn:String="",val availability:Map<String,String> = emptyMap()) {
    fun notice(date: LocalDate): String? = availability[date.toString()]?.takeIf{it.isNotBlank()} ?: if (date.dayOfWeek in daysOff) "Department day off" else timeOff.firstOrNull { !date.isBefore(it.start) && !date.isAfter(it.end) }?.let { "Class suspended: ${it.teacher}. ${it.reason}" }
}
enum class AttendanceStatus { Present, Late, Absent, Excused }
data class AttendanceRecord(val id: String, val courseId: String, val date: LocalDate, val status: AttendanceStatus, val timeIn: String?, val source: String = "rfid", val courseTitle: String = "", val courseCode: String = "", val timeOut: String? = null)
data class CampusActivity(val id: String, val title: String, val kind: String, val date: LocalDate, val time: String, val venue: String, val description: String, val attendance: String = "Pending", val endDate: LocalDate? = null, val status: String = "scheduled", val required: Boolean = true, val imageUrl: String? = null, val images: List<String> = emptyList())
data class StudentMessage(val id: String, val sender: String, val subject: String, val body: String, val date: LocalDate, val read: Boolean = false, val senderId: String? = null)
data class StudentData(val student: Student, val courses: List<Course>, val attendance: List<AttendanceRecord>, val activities: List<CampusActivity>, val messages: List<StudentMessage>, val counts: Map<AttendanceStatus, Int>? = null, val compliance: Map<String, Int> = emptyMap(), val subjectCompliance: Map<String, Map<String, Int>> = emptyMap(), val enabledTerms: List<String> = listOf("Prelim", "Midterm", "Final"), val passingGrade: Double = 75.0, val windowDays: Int = 21, val timezone: String = "Asia/Taipei") {
    fun today(clock: java.time.Clock = java.time.Clock.systemUTC()): LocalDate = LocalDate.now(clock.withZone(java.time.ZoneId.of(timezone)))
    val historyLabel: String get() = if (windowDays == 0) "All recorded history" else "Last $windowDays days"
    fun course(id: String) = courses.firstOrNull { it.id == id } ?: attendance.first { it.courseId == id }.let { Course(id, it.courseTitle, "", "", emptyList(), "", null, null, null, code = it.courseCode) }
    fun count(status: AttendanceStatus) = counts?.get(status) ?: attendance.count { it.status == status }
    // Attendance rate includes late arrivals and excludes excused sessions.
    // Compliance totals are separate authoritative server values.
    val attendancePercent: Int get() {
        val total = count(AttendanceStatus.Present) + count(AttendanceStatus.Late) + count(AttendanceStatus.Absent)
        return if (total == 0) 0 else (count(AttendanceStatus.Present) + count(AttendanceStatus.Late)) * 100 / total
    }
}

interface StudentRepository { suspend fun load(): StudentData }

/** Local fixtures only: no authentication, network requests, or attendance writes. */
class DemoStudentRepository : StudentRepository {
    override suspend fun load(): StudentData {
        delay(850) // Deliberate preview latency to exercise the loading UI.
        return sampleStudentData()
    }
}

fun LocalDate.displayDate(): String = format(DateTimeFormatter.ofPattern("MMM d, yyyy", Locale.ENGLISH))

fun sampleStudentData(today: LocalDate = LocalDate.now()): StudentData {
    val mwf = listOf(DayOfWeek.MONDAY, DayOfWeek.WEDNESDAY, DayOfWeek.FRIDAY)
    val tth = listOf(DayOfWeek.TUESDAY, DayOfWeek.THURSDAY)
    val courses = listOf(
        Course("IT301", "Application Development", "Prof. Maria Santos", "Computer Lab 2", mwf, "8:00 AM – 9:30 AM", 91.0, 94.0, null),
        Course("IT302", "Database Management", "Prof. Rafael Garcia", "Room 204", mwf, "10:00 AM – 11:30 AM", 89.0, 92.0, null),
        Course("GE103", "Ethics", "Prof. Ana Reyes", "Room 301", tth, "9:00 AM – 10:30 AM", 94.0, 95.0, 93.0, 93.8),
        Course("IT303", "Integrative Programming", "Prof. Carlo Mendoza", "Computer Lab 1", tth, "1:00 PM – 2:30 PM", 90.0, null, null)
    )
    val records = (1L..21L).flatMap { daysAgo ->
        val date = today.minusDays(daysAgo)
        courses.filter { date.dayOfWeek in it.days }.map { course ->
            AttendanceRecord("${course.id}-$date", course.id, date,
                when { daysAgo == 4L -> AttendanceStatus.Late; daysAgo == 7L -> AttendanceStatus.Absent; daysAgo == 11L -> AttendanceStatus.Excused; else -> AttendanceStatus.Present },
                if (daysAgo == 7L || daysAgo == 11L) null else if (daysAgo == 4L) course.time.substringBefore(" – ").replace(":00", ":12") else course.time.substringBefore(" – "))
        }
    }
    return StudentData(
        Student("Juan Dela Cruz", "2026-0001", "juan.delacruz@student.local", "BS Information Technology", "BSIT 3-A"),
        courses, records,
        listOf(
            CampusActivity("innovation", "Campus Innovation Week", "Event", today.plusDays(4), "9:00 AM – 4:00 PM", "University Auditorium", "A week of ideas, student projects, and conversations about the future. Meet student innovators and explore projects from across the campus.\n\nBring your school ID. Attendance is recorded at the venue's RFID reader."),
            CampusActivity("formation", "Pause. Reflect. Reconnect.", "Recollection", today.plusDays(7), "8:00 AM – 12:00 PM", "Formation Center", "A morning of reflection and community for BSIT 3-A. Take a step back from your routine, reconnect with your purpose, and spend time with your classmates.\n\nPlease arrive 15 minutes early with your school ID."),
            CampusActivity("welcome", "Student Organization Fair", "Event", today.minusDays(5), "10:00 AM – 3:00 PM", "Campus Quadrangle", "Discover student organizations, meet the teams, and find your campus community.", "Present")
        ),
        listOf(
            StudentMessage("1", "Prof. Maria Santos", "Your next project starts here", "For our next Application Development class, bring a short proposal for a mobile application that solves a campus problem. Include your target users and three core features. We will discuss your ideas in class.", today),
            StudentMessage("2", "Student Affairs Office", "Innovation Week is coming", "Campus Innovation Week begins ${today.plusDays(4).displayDate()}. Check the Campus tab for the schedule and venue. Please bring your school ID for attendance.", today.minusDays(1)),
            StudentMessage("3", "Formation Office", "BSIT 3-A recollection reminder", "Your class recollection is scheduled for ${today.plusDays(7).displayDate()} at the Formation Center. Please be at the venue by 7:45 AM.", today.minusDays(2))
        )
    )
}
