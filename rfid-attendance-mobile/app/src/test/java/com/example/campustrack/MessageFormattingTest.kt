package com.example.campustrack

import com.example.campustrack.ui.messageHtml
import org.junit.Assert.*
import org.junit.Test

class MessageFormattingTest {
    @Test fun textRemainsInertAndFormattingIsExplicit(){val html=messageHtml("<script>alert(1)</script> **Notice** _today_ __required__","plain");assertFalse(html.contains("<script>"));assertTrue(html.contains("&lt;script&gt;"));assertTrue(html.contains("<strong>Notice</strong>"));assertTrue(html.contains("<em>today</em>"));assertTrue(html.contains("<u>required</u>"))}
    @Test fun onlyHttpLinksBecomeAnchors(){assertTrue(messageHtml("[Campus](https://example.test)","plain").contains("href=\"https://example.test\""));assertFalse(messageHtml("[Unsafe](javascript:alert)","plain").contains("<a "))}
    @Test fun linkUrlsRemainLiteral(){val html=messageHtml("[**A link**](https://example.test/a_b_c?q=x&y=2)","plain");assertTrue(html.contains("href=\"https://example.test/a_b_c?q=x&amp;y=2\""));assertTrue(html.contains("<strong>A link</strong>"));assertFalse(html.contains("<em>b</em>"))}
}
