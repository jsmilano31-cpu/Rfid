<?php
declare(strict_types=1);
namespace App\Services;
final class AnalyticsMetrics
{
    public const VERSION='20261007.1';
    public const SECTIONS=['overview'=>'Overview','attendance'=>'Attendance & punctuality','grades'=>'Academic outcomes','activities'=>'Campus participation','compliance'=>'Compliance patterns','resources'=>'Scheduling & resources','scanners'=>'Scanner reliability','communications'=>'Communication reach','quality'=>'Data quality'];
    public const FORMULAS=[
        'participation'=>'(Present + Late) ÷ (Present + Late + Absent), finalized eligible records only. Excused and unrecorded observations are reported separately.',
        'lateness'=>'Late ÷ (Present + Late). Minutes late require a historical scheduled start and a recorded arrival.',
        'grades'=>'Finalized scores only, one per student / subject / section / semester; lecture and laboratory share a grade. Midterms/Endterms comparisons use the same paired results with both enabled scores. Draft and missing results are excluded from score statistics. Passing uses the grading policy captured at finalization.',
        'activities'=>'Required completed attendance ÷ eligible required participants excluding Excused. Late counts as completed only when required checkout is complete. Optional attendance is separate.',
        'compliance'=>'Absences plus floor(Late ÷ historical tardies-per-absence), grouped by student, offering and policy version. Unknown historical policies have no inferred tardy equivalents.',
        'resources'=>'Booked minutes from class schedules; overlapping intervals use start < other end and end > other start. These measure bookings, not occupancy.',
        'scanners'=>'Rejected scans ÷ (attendance-recorded + event-attendance-recorded + rejected scans). Card registration is excluded. Device uptime is not measured.',
        'communications'=>'Recipients with a recorded first open by the selected period end ÷ prospectively tracked delivered recipient copies sent in that period. Delay uses server timestamps for prospectively tracked deliveries. Original legacy first-open delays are unavailable.',
        'quality'=>'Recorded eligible observations ÷ expected observations where prospective roster history is available. Unknown historic eligibility is not reconstructed.',
    ];
    /** Versioned sources and grains shared by UI explanations, drilldowns and exports. */
    public const DEFINITIONS=[
        'attendance'=>['sources'=>['attendance_records','attendance_sessions','analytics_occurrences','analytics_attendance','analytics_daily'],'grain'=>'student / class offering / attendance date','numerator'=>'Finalized Present + Late; lateness numerator is Late','denominator'=>'Finalized Present + Late + Absent; lateness denominator is Present + Late','exclusions'=>['Excused','unrecorded expected observations','provisional sessions','known ineligible observations'],'scope'=>'Authorized campus grants, assigned classes, or own student records'],
        'grades'=>['sources'=>['grades','class_enrollments','analytics_history'],'grain'=>'student / shared subject / section / semester / finalized result','numerator'=>'Finalized subject scores; passing results under captured policy','denominator'=>'Finalized results with a score; known captured policies for passing','exclusions'=>['drafts','unresolved component conflicts','missing scores','unknown historical policies for passing','disabled captured grading periods'],'scope'=>'Authorized grade management, assigned classes, or own student results'],
        'activities'=>['sources'=>['events','event_attendance','recollections','recollection_attendance','analytics_history'],'grain'=>'participant / activity, including multi-day events and recollections','numerator'=>'Required completed Attended or Late records with required checkout','denominator'=>'Captured eligible required audience minus Excused','exclusions'=>['draft or cancelled activities','optional audiences','unknown historical audience denominators','open activities from final rates'],'scope'=>'Authorized activity management or own participant records'],
        'compliance'=>['sources'=>['analytics_attendance','analytics_history','disciplinary_violations'],'grain'=>'student / offering / captured policy, or authorized disciplinary classification','numerator'=>'Absences plus floor(tardies / captured conversion threshold)','denominator'=>'No percentage; counts retain finalized observation sample','exclusions'=>['provisional sessions','unknown conversion rules','private narratives and evidence'],'scope'=>'Existing compliance and disciplinary grants, assigned classes, or own records'],
        'resources'=>['sources'=>['class_schedules','class_enrollments','class_seat_assignments'],'grain'=>'current offering / weekly meeting interval','numerator'=>'Booked minutes; distinct permitted seated students','denominator'=>'Permitted enrolled students for seat coverage','exclusions'=>['cancelled offerings','inaccessible offerings','inferred actual occupancy'],'scope'=>'Authorized resource management, assigned classes, or own enrolled schedules and seats'],
        'scanners'=>['sources'=>['device_scan_logs','devices'],'grain'=>'authenticated recorded attendance scan attempt','numerator'=>'Rejected attempts','denominator'=>'Recorded class attendance + activity attendance + rejected attempts','exclusions'=>['registration attempts','unrecorded or pre-identification requests','estimated uptime'],'scope'=>'Authorized room or RFID management only'],
        'communications'=>['sources'=>['messages','message_recipients'],'grain'=>'delivered recipient copy / own sent message','numerator'=>'Recipient copies with a server first-open timestamp','denominator'=>'Delivered recipient copies','exclusions'=>['other senders’ messages','legacy deliveries from reach and delay denominators','inferred legacy open timestamps'],'scope'=>'Sender’s own messages only'],
        'quality'=>['sources'=>['analytics_attendance','analytics_daily','courses'],'grain'=>'eligible expected observation or permitted offering configuration','numerator'=>'Known expected observations with a record','denominator'=>'Known expected observations','exclusions'=>['unknown historical eligibility from coverage denominator','fabricated credit units'],'scope'=>'Same domain and academic relationships as the supporting records'],
    ];
    public static function definitions(string $section): array
    {
        $keys=$section==='overview'?['attendance','grades','activities','quality']:[$section];$out=[];
        foreach($keys as $key)if(isset(self::DEFINITIONS[$key]))$out[$key]=self::DEFINITIONS[$key]+['version'=>self::VERSION,'formula'=>self::FORMULAS[$key==='attendance'?'participation':$key],'filters'=>['from','to','semester_id','department_id','program_id','section_id','teacher_id','course_id','offering_id']];
        return $out;
    }
    public static function rate(int|float $numerator,int|float $denominator): ?float{return $denominator>0?round(100*$numerator/$denominator,2):null;}
}
