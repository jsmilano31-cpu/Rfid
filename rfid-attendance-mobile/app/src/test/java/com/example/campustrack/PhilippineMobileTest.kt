package com.example.campustrack

import com.example.campustrack.data.PhilippineMobile
import org.junit.Assert.*
import org.junit.Test

class PhilippineMobileTest {
    @Test fun pastedFormatsNormalizeToOneDestination(){listOf("0917 123 4567","+63 917 123 4567","639171234567","9171234567","00639171234567").forEach{assertEquals("+639171234567",PhilippineMobile.normalized(it))}}
    @Test fun blankRemainsOptional(){assertNull(PhilippineMobile.normalized(""));assertTrue(PhilippineMobile.valid(""))}
    @Test fun invalidNumbersCannotBecomeSmsRecipients(){listOf("123","+1 917 123 4567","08171234567","091712345678","abc09171234567").forEach{assertFalse(PhilippineMobile.valid(it))}}
}
