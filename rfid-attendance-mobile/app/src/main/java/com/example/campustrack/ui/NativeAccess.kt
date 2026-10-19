package com.example.campustrack.ui

internal fun nativeRouteAllowed(route:String,permissions:Set<String>,roles:List<String>,smsSettings:Boolean):Boolean {
    fun can(vararg grants:String)=grants.any{it in permissions}
    return when(route.substringBefore('/')){
        "home"->can("view_dashboard")
        "schedule","scheduled","course","class"->can("view_schedule")
        "attendance","attendance-calendar","attendance-date"->can("view_attendance","correct_class_attendance","manage_attendance")
        "policy"->can("manage_attendance_policies")
        "roster"->can("view_class_rosters")
        "grades"->can("view_grades","manage_grades")
        "analytics"->can("view_analytics")
        "goals"->"Student" in roles&&can("manage_own_learning_goals")&&can("view_analytics")
        "alerts"->can("send_attendance_sms","correct_class_attendance","manage_attendance","view_class_rosters")
        "sms-settings"->smsSettings
        "messages"->can("view_messages","send_messages")
        "events","event"->can("view_events","manage_events")
        "recollections"->can("view_recollections","manage_recollections")
        "calendar"->can("view_schedule","view_events","manage_events","view_recollections","manage_recollections","manage_academic_calendar")
        "people","person"->can("view_people","manage_users")
        "search"->can("use_search")
        "profile"->can("manage_own_profile")&&"Administrator" !in roles
        "settings"->can("manage_own_settings")
        "time-off"->"Teacher" in roles&&can("manage_teacher_time_off")
        "live","seat-subjects"->can("assign_class_seats","manage_seat_plans")
        "compliance"->can("view_compliance","manage_compliance","manage_reports")
        "manage","record"->when(route.substringAfter('/').substringBefore('/')){
            "user","users"->can("manage_users")
            "role"->can("manage_roles")
            "rfid"->can("manage_rfid")
            "room","rooms","device"->can("manage_rooms")
            "audit","audit-trail"->can("view_audit_logs")
            "grading"->can("manage_grading_settings")
            "policies"->can("manage_attendance_policies")
            "terms","calendar","days-off"->can("manage_attendance_policies")&&can("manage_academic_calendar")
            "seats"->can("manage_seat_plans")
            "compliance"->can("view_compliance","manage_compliance","manage_reports")
            else->can("manage_academic_structure")
        }
        else->true
    }
}
