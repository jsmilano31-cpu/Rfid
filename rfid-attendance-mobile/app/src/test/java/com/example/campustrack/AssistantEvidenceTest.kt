package com.example.campustrack

import com.example.campustrack.data.*
import org.junit.Assert.*
import org.junit.Test

class AssistantEvidenceTest {
    @Test fun requiresNativeArmNotTranslation() {
        assertFalse(nativeAiSupported("x86_64"))
        assertFalse(nativeAiSupported(null))
        assertTrue(nativeAiSupported("arm64-v8a"))
    }
    @Test fun recordsStayAccurateAndExcludeAccountIdentifiers() {
        val data = sampleStudentData()
        val evidence = assistantEvidence(data, false)
        assertEquals(94.0, org.json.JSONObject(evidence).getJSONArray("courses").getJSONObject(0).getDouble("midterm"), 0.001)
        assertTrue(evidence.contains("null"))
        assertFalse(evidence.contains(data.student.email))
        assertFalse(evidence.contains(data.student.schoolId))
        assertFalse(evidence.contains(data.messages.first().body))
    }
    @Test fun teacherContextDoesNotRepresentStudentGradesAsPersonal() {
        val evidence = assistantEvidence(sampleStudentData(), true)
        assertTrue(evidence.contains("teacher"))
        assertFalse(evidence.contains("\"midterm\""))
        assertFalse(evidence.contains("\"attendance\""))
    }
    @Test fun largeContextExplicitlySignalsTruncation() {
        val data = sampleStudentData()
        val evidence = assistantEvidence(data.copy(courses = List(12) { data.courses.first().copy(section = "x".repeat(500)) }), false)
        assertTrue(evidence.length < 3500)
        assertTrue(evidence.contains("truncated"))
    }
}
