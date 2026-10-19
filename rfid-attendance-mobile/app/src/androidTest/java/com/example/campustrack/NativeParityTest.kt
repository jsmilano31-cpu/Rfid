package com.example.campustrack

import androidx.compose.runtime.*
import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.example.campustrack.data.*
import com.example.campustrack.ui.NativePortalApp
import com.example.campustrack.ui.theme.CampusTrackTheme
import kotlinx.coroutines.runBlocking
import org.json.JSONObject
import org.junit.*
import org.junit.runner.RunWith
import java.io.File

/** Opt-in native workflow tests. Credentials are supplied for an isolated local fixture only. */
@RunWith(AndroidJUnit4::class)
class NativeParityTest {
    @get:Rule val compose=createComposeRule()
    private lateinit var api:ApiClient
    private fun start(role:String,dark:Boolean=false){
        Assume.assumeTrue(BuildConfig.API_BASE_URL.startsWith("http://10.0.2.2:"))
        val registry=InstrumentationRegistry.getInstrumentation()
        val schoolId=InstrumentationRegistry.getArguments().getString("${role}Id")
        Assume.assumeTrue(!schoolId.isNullOrBlank())
        api=ApiClient(registry.targetContext);api.clearSession()
        runBlocking{api.post("auth/login.php",JSONObject().put("identifier",schoolId).put("password","Password123!"));api.session()}
        val prefs=AppPreferenceController(registry.targetContext).apply{apply(AppPreferences(themeMode=if(dark)"dark"else"light",reducedMotion=true,pushEnabled=false))}
        compose.setContent{CompositionLocalProvider(LocalAppPreferences provides prefs){CampusTrackTheme(prefs.value){NativePortalApp(api, {},{})}}}
        waitText(if(role=="admin")"Campus workspace"else"Hello,")
    }
    private fun waitText(text:String){compose.waitUntil(25_000){compose.onAllNodes(hasText(text,substring=true)).fetchSemanticsNodes().isNotEmpty()}}
    private fun openMore(label:String){compose.onNodeWithTag("nav/more").performClick();compose.onAllNodes(hasScrollAction()).onFirst().performScrollToNode(hasText(label));compose.onNodeWithText(label).performClick()}
    private fun capture(name:String){compose.waitUntil(25_000){compose.onAllNodesWithText("Loading records…").fetchSemanticsNodes().isEmpty()};compose.waitForIdle();android.os.SystemClock.sleep(900);val registry=InstrumentationRegistry.getInstrumentation();val directory=File(registry.targetContext.externalCacheDir,"parity-shots").apply{mkdirs()};registry.uiAutomation.takeScreenshot().let{bitmap->File(directory,"$name.png").outputStream().use{bitmap.compress(android.graphics.Bitmap.CompressFormat.PNG,100,it)}}}
    @After fun logout(){if(::api.isInitialized)runBlocking{runCatching{api.post("auth/logout.php")};api.clearSession()}}

    @Test fun studentGradesGoalsAndFilters(){
        start("student");capture("student-home-light")
        openMore("Grades");waitText("My grades");waitText("Semester");compose.onAllNodes(hasScrollAction()).onFirst().performScrollToNode(hasText("Teacher:",substring=true));compose.onAllNodes(hasText("Teacher:",substring=true)).fetchSemanticsNodes().let{Assert.assertTrue(it.isNotEmpty())};capture("student-grades-light")
        openMore("My goals");waitText("My goals");compose.onNodeWithText("Set a goal").performClick();waitText("Learning goal");capture("student-goal-editor-light")
    }
    @Test fun studentAttendanceAndSemesterSchedule(){
        start("student",true);compose.onNodeWithTag("nav/attendance").performClick();waitText("Attendance");capture("student-attendance-dark")
        compose.onNodeWithTag("nav/schedule").performClick();waitText("Your schedule");compose.onNodeWithText("Semester").assertExists();capture("student-schedule-dark")
    }
    @Test fun teacherCorrectionAndPreparedSeatPlans(){
        start("teacher",true);capture("teacher-home-dark")
        compose.onNodeWithTag("nav/attendance").performClick();waitText("Class attendance");compose.onAllNodesWithText("Open attendance").onFirst().performClick();waitText("Attendance");compose.onNodeWithText("Finalize date").assertDoesNotExist();capture("teacher-attendance-dark")
        openMore("Seat plans");waitText("Seat plans");capture("teacher-prepared-seats-dark")
        compose.onNodeWithText("SMS settings").assertDoesNotExist()
    }
    @Test fun administratorAccountsAndSmsSettings(){
        start("admin");openMore("Accounts");waitText("User records");compose.onNodeWithText("Create user").performClick();waitText("Phone number (optional)");compose.onNodeWithText("Phone number (optional)").assertExists();capture("admin-account-editor-light")
    }
    @Test fun studentAnalyticsAndEventDetails(){
        start("student")
        openMore("Analytics");waitText("My progress");waitText("Semester");capture("student-analytics-light")
        compose.onAllNodes(hasScrollAction()).onFirst().performScrollToNode(hasText("Refine view",substring=true))
        compose.onNode(hasText("Refine view",substring=true) and hasClickAction()).performClick();waitText("Refine analytics");capture("student-analytics-filters-light")
    }
    @Test fun administratorSmsSettingsAndEventEditor(){
        start("admin",true);openMore("SMS settings");waitText("Configure SMS");capture("admin-sms-dark")
        openMore("Events");waitText("Create event");compose.onNodeWithText("Create event").performClick();waitText("Event title *");capture("admin-event-editor-dark")
    }
    @Test fun profileReadsAuthoritativePrivacyAfterAnExternalChange(){
        start("student",true)
        runBlocking{api.post("student/profile-visibility.php",JSONObject().put("is_profile_public",false))}
        openMore("Profile");waitText("My profile")
        compose.onAllNodes(hasScrollAction()).onFirst().performScrollToNode(hasText("Other users see only that your profile is private."))
        compose.onNodeWithText("Other users see only that your profile is private.").assertExists();capture("student-profile-private-dark")
        runBlocking{api.post("student/profile-visibility.php",JSONObject().put("is_profile_public",true))}
    }
    @Test fun sharedEventPlaceholderDecodes(){
        start("student")
        val context=InstrumentationRegistry.getInstrumentation().targetContext
        val result=runBlocking{coil.Coil.imageLoader(context).execute(coil.request.ImageRequest.Builder(context).data(BuildConfig.API_BASE_URL+"assets/images/default-event-banner.svg").build())}
        Assert.assertTrue("The fallback event asset must render",result is coil.request.SuccessResult)
    }
    @Test fun fullscreenTrackingShowsManualFallback(){
        start("admin",true)
        val today=runBlocking{api.get("workspace/events.php?mode=options").getString("today")}
        val event=runBlocking{api.post("workspace/events.php",JSONObject().put("action","create").put("title","Native tracking fixture").put("description","Isolated UI fixture").put("venue","Fixture hall").put("event_date",today).put("end_date",today).put("start_time","00:00").put("end_time","23:59").put("targets",org.json.JSONArray().put(JSONObject().put("type","user").put("id",5))))}.getLong("id")
        val title=runBlocking{api.get("workspace/events.php?mode=details&id=$event").getJSONObject("event").getString("title")}
        try{
            openMore("Events");waitText("Search events");compose.onNodeWithText("Search events").performTextInput("Native tracking fixture");compose.onNodeWithText("Search events").performImeAction();waitText(title)
            compose.onNodeWithText(title).performScrollTo().performClick();waitText("Dates")
            compose.onAllNodes(hasScrollAction()).onFirst().performScrollToNode(hasText("Start event tracking"));compose.onNodeWithText("Start event tracking").performClick();waitText("Ready for connected scanner");capture("admin-event-tracking-dark")
            compose.onAllNodes(hasScrollAction()).onLast().performScrollToNode(hasText("School ID number"));compose.onNodeWithText("School ID number").assertIsDisplayed()
            compose.onNodeWithText("Exit tracking").performScrollTo().performClick()
        }finally{runBlocking{api.post("workspace/events.php",JSONObject().put("action","delete").put("id",event))}}
    }
}
