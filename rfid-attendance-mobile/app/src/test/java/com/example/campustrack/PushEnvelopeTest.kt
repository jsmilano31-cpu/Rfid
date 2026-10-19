package com.example.campustrack

import com.example.campustrack.data.acceptedPush
import org.junit.Assert.*
import org.junit.Test

class PushEnvelopeTest {
    private val data = mapOf("account_id" to "5", "notification_id" to "100", "revision" to "2")
    @Test fun onlyCurrentAccountCanReceive() {
        assertNull(acceptedPush(null, data))
        assertNull(acceptedPush("6", data))
        assertEquals(100L to 2, acceptedPush("5", data))
    }
    @Test fun malformedMessagesCannotCreateNotifications() {
        assertNull(acceptedPush("5", data + ("notification_id" to "-1")))
        assertNull(acceptedPush("5", data - "revision"))
        assertNull(acceptedPush("5", data + ("revision" to "999999999999999999999")))
    }
}
