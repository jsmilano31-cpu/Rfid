package com.example.campustrack.data

import org.json.JSONArray
import org.json.JSONObject
import java.time.DayOfWeek
import java.time.LocalDate
import java.time.LocalTime
import java.time.format.DateTimeFormatter
import java.util.Locale

fun JSONArray.objects(): List<JSONObject> = (0 until length()).map { getJSONObject(it) }
private fun JSONObject.nullText(key: String): String? = if (isNull(key)) null else getString(key)
private fun JSONObject.score(key: String): Double? = if (isNull(key)) null else getDouble(key)
private fun JSONObject.numbers(keys: List<String>) = keys.associateWith { optInt(it, 0) }
private fun clock(value: String?): String? = value?.let { LocalTime.parse(it).format(DateTimeFormatter.ofPattern("h:mm a", Locale.ENGLISH)) }
private fun period(start: String, end: String) = "${clock(start)} – ${clock(end)}"

class RemoteStudentRepository(val api: ApiClient) : StudentRepository {
    override suspend fun load(): StudentData = parsePortal(api.get("student/portal.php"))
    suspend fun markRead(id: String) { api.post("student/message-read.php", JSONObject().put("message_id", id.toLong())) }
    suspend fun updateEmail(email: String) { api.post("student/profile-update.php", JSONObject().put("email", email)) }
    suspend fun updateProfileVisibility(isPublic: Boolean) { api.post("student/profile-visibility.php", JSONObject().put("is_profile_public", isPublic)) }
    suspend fun changePassword(current: String, password: String, confirmation: String) {
        api.post("auth/password.php", JSONObject().put("current_password", current).put("password", password).put("password_confirmation", confirmation))
    }
}

fun parsePortal(json: JSONObject): StudentData {
    val profile = json.getJSONObject("student")
    val card = profile.optJSONObject("rfid")
    val roles = profile.getJSONArray("roles")
    val student = Student(
        listOfNotNull(profile.nullText("first_name"), profile.nullText("middle_name"), profile.nullText("last_name")).joinToString(" "),
        profile.getString("school_id"), profile.getString("email"), profile.nullText("program") ?: "Not assigned", profile.nullText("section") ?: "Not assigned",
        profile.nullText("department") ?: "Not assigned", (0 until roles.length()).map { roles.getString(it) }, profile.nullText("profile_image_url"), card?.nullText("masked_uid"), card?.nullText("status"), profile.optBoolean("is_profile_public", true)
    )
    val courses = json.getJSONArray("courses").objects().map { row ->
        val meetings = row.getJSONArray("meetings").objects().map {
            Meeting(DayOfWeek.valueOf(it.getString("day_of_week").uppercase(Locale.ENGLISH)), period(it.getString("start_time"), it.getString("end_time")), it.nullText("room") ?: "Room to be announced", it.optString("building", "").takeUnless { it == "null" }.orEmpty(), it.optString("floor", "").takeUnless { it == "null" }.orEmpty(), it.optString("room_code", "").takeUnless { it == "null" }.orEmpty())
        }
        Course(row.getLong("id").toString(), row.getString("title"), row.getJSONArray("teachers").objects().joinToString(", ") { it.getString("name") }.ifBlank { "Teacher to be assigned" },
            meetings.firstOrNull()?.room ?: "Room to be announced", meetings.map { it.day }.distinct(), meetings.firstOrNull()?.time ?: "Schedule to be announced",
            row.score("prelim"), row.score("midterm"), row.score("final"), row.score("overall"), "${row.getString("course_code")} ${row.optString("component_type", "LEC")}", row.nullText("grade_status") ?: "Not entered", meetings, row.getString("section"), row.getString("semester"), row.optString("status", "active"), row.getJSONArray("teachers").objects().map { Teacher(it.getLong("id").toString(), it.getString("name")) }, (row.optJSONArray("time_off") ?: JSONArray()).objects().map { TimeOff(LocalDate.parse(it.getString("starts_on")), LocalDate.parse(it.getString("ends_on")), it.optString("reason", ""), it.getString("teacher")) }, (row.optJSONArray("days_off") ?: JSONArray()).let { days -> (0 until days.length()).map { DayOfWeek.valueOf(days.getString(it).uppercase(Locale.ENGLISH)) } }, row.nullText("grade_updated_at").orEmpty(), row.optString("semester_id", ""), row.optString("school_year", ""), row.optString("starts_on", ""), row.optString("ends_on", ""))
    }
    val attendance = json.getJSONArray("attendance").objects().map {
        AttendanceRecord(it.getLong("id").toString(), it.getLong("class_offering_id").toString(), LocalDate.parse(it.getString("attendance_date")), AttendanceStatus.valueOf(it.getString("status")), clock(it.nullText("time_in")), it.getString("source"), it.getString("title"), "${it.getString("course_code")} ${it.optString("component_type", "LEC")}", clock(it.nullText("time_out")))
    }
    val activities = json.getJSONArray("activities").objects().map { row ->
        val images = row.optJSONArray("images") ?: JSONArray()
        CampusActivity(row.getString("id"), row.getString("title"), row.getString("kind"), LocalDate.parse(row.getString("date")), period(row.getString("start_time"), row.getString("end_time")), row.getString("venue"), row.getString("description"), row.nullText("attendance") ?: "Pending", row.nullText("end_date")?.let(LocalDate::parse), row.getString("status"), row.getBoolean("required"), row.nullText("image_url"), (0 until images.length()).map { images.getString(it) })
    }
    val messages = json.getJSONArray("messages").objects().map { StudentMessage(it.getLong("id").toString(), it.getString("sender"), it.getString("subject"), it.getString("body"), LocalDate.parse(it.getString("created_at").take(10)), !it.isNull("read_at"), it.nullText("sender_id")) }
    val counts = json.getJSONArray("attendance_counts").objects().associate { AttendanceStatus.valueOf(it.getString("status")) to it.getInt("total") }
    val keys = listOf("absences", "tardies", "tardy_equivalent", "missed_events", "missed_recollections", "total_deficiencies", "class_deficiencies")
    val compliance = (json.optJSONObject("compliance") ?: JSONObject()).numbers(keys)
    val subjects = json.getJSONArray("subject_compliance").objects().associate { it.getLong("class_offering_id").toString() to it.numbers(keys) }
    val grading = json.getJSONObject("grading")
    val terms = listOf("Prelim", "Midterm", "Final").filter { grading.optInt("has_${it.lowercase(Locale.ENGLISH)}", 0) == 1 }
    return StudentData(student, courses, attendance, activities, messages, counts, compliance, subjects, terms, grading.getDouble("passing_grade"), json.getInt("attendance_window_days"), json.optString("timezone", "Asia/Taipei"))
}
