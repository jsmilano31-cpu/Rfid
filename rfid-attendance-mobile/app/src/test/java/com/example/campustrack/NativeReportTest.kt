package com.example.campustrack

import com.example.campustrack.ui.csvRecords
import org.junit.Assert.*
import org.junit.Test

class NativeReportTest {
    @Test fun quotedFieldsKeepCommasAndLineBreaks(){assertEquals(listOf(listOf("Name","Comment"),listOf("José","One, two\nThree \"quoted\"")),csvRecords("\uFEFFName,Comment\r\nJosé,\"One, two\nThree \"\"quoted\"\"\"\r\n"))}
    @Test fun emptyCellsRemainEmpty(){assertEquals(listOf(listOf("","","last")),csvRecords(",,last"))}
}
