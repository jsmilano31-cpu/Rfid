package com.example.campustrack

import androidx.compose.ui.test.*
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.test.ext.junit.runners.AndroidJUnit4
import org.junit.Before
import com.example.campustrack.ui.StudentApp
import com.example.campustrack.ui.theme.CampusTrackTheme
import com.example.campustrack.data.DemoStudentRepository
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class StudentPortalTest {
    @get:Rule val compose = createComposeRule()

    @Before fun setUp() { compose.setContent { ParityTestTheme { StudentApp(DemoStudentRepository()) } } }

    private fun awaitPortal() {
        compose.waitUntil(10_000) { compose.onAllNodesWithText("Hello, Juan.").fetchSemanticsNodes().isNotEmpty() }
        compose.waitForIdle()
    }

    @Test fun attendanceSearchAndFilterSurviveReload() {
        awaitPortal()
        compose.onNodeWithTag("nav/attendance").performClick()
        compose.onNodeWithText("Search subjects").performTextInput("no such subject")
        compose.onNodeWithText("No matching attendance").assertIsDisplayed()
        compose.onNodeWithContentDescription("Refresh records").performClick()
        compose.waitUntil(10_000) { compose.onAllNodesWithContentDescription("Loading portal").fetchSemanticsNodes().isEmpty() }
        compose.onNodeWithText("No matching attendance").assertIsDisplayed()
        compose.waitUntil(10_000) { compose.onAllNodesWithText("No matching attendance").fetchSemanticsNodes().isNotEmpty() }
        compose.onNodeWithContentDescription("Clear search").performClick()
        compose.onNodeWithTag("filter/Late").performClick()
        compose.onNodeWithTag("nav/attendance").assertIsSelected()
    }

    @Test fun openingMessageMarksItReadAndBackReturnsToInbox() {
        awaitPortal()
        compose.onNodeWithContentDescription("Messages, 3 unread").performClick()
        compose.onNodeWithText("Your next project starts here").performClick()
        compose.onNodeWithText("Read").assertIsDisplayed()
        compose.onNodeWithContentDescription("Messages, 2 unread").assertExists()
        compose.onNodeWithContentDescription("Back").performClick()
        compose.onNodeWithTag("filter/Unread").performClick()
        compose.onNodeWithText("Your next project starts here").assertDoesNotExist()
    }

    @Test fun scheduleDayAndProfileValidationWork() {
        awaitPortal()
        compose.onNodeWithTag("nav/schedule").performClick()
        compose.onNodeWithText("Mon").performClick()
        compose.onNodeWithText("8:00 AM – 9:30 AM").assertIsDisplayed()
        compose.onNodeWithTag("nav/more").performClick()
        compose.onAllNodes(hasScrollAction()).onFirst().performScrollToNode(hasText("My profile"))
        compose.onNodeWithText("My profile").performClick()
        compose.onNode(hasScrollAction()).performScrollToNode(hasText("Edit"))
        compose.onNodeWithText("Edit").performClick()
        compose.onNodeWithText("Email address").performTextReplacement("invalid")
        compose.onNodeWithText("Save").performClick()
        compose.onNodeWithText("Enter a valid email address.").assertIsDisplayed()
        compose.onNodeWithText("Email address").performTextReplacement("juan@example.com")
        compose.onNodeWithText("Save").performClick()
        compose.onNodeWithText("Email updated.").assertExists()
    }
}
