package com.example.campustrack.data

object PhilippineMobile {
    fun local(raw:String):String {if(!raw.matches(Regex("[+0-9\\s().-]*")))return raw;val digits=raw.filter(Char::isDigit);return when{digits.startsWith("0063")&&digits.length>=14->digits.drop(4);digits.startsWith("63")&&digits.length>=12->digits.drop(2);digits.startsWith("0")&&digits.length>=11->digits.drop(1);else->digits}}
    fun valid(raw:String)=raw.isBlank()||(raw.matches(Regex("[+0-9\\s().-]+"))&&local(raw).matches(Regex("9[0-9]{9}")))
    fun normalized(raw:String):String? {require(valid(raw)){"Enter a Philippine mobile number with ten digits starting with 9."};return local(raw).takeIf{it.isNotBlank()}?.let{"+63$it"}}
}
