package com.example.campustrack.ui

/** Only explicit lightweight formatting is interpreted; all user text is HTML-escaped first. */
internal fun messageHtml(body:String,style:String):String {
    fun escape(text:String)=text.replace("&","&amp;").replace("<","&lt;").replace(">","&gt;").replace("\"","&quot;").replace("'","&#39;")
    fun emphasis(text:String):String=escape(text)
        .replace(Regex("\\*\\*(.+?)\\*\\*"),"<strong>$1</strong>")
        .replace(Regex("__(.+?)__"),"<u>$1</u>")
        .replace(Regex("(?<!_)_(?!_)(.+?)(?<!_)_(?!_)"),"<em>$1</em>")
    fun inline(text:String):String {
        val links=Regex("\\[([^]]+)]\\((https?://[^\\s)]+)\\)")
        val output=StringBuilder();var position=0
        links.findAll(text).forEach { match ->
            output.append(emphasis(text.substring(position,match.range.first)))
            output.append("<a href=\"").append(escape(match.groupValues[2])).append("\">")
                .append(emphasis(match.groupValues[1])).append("</a>")
            position=match.range.last+1
        }
        return output.append(emphasis(text.substring(position))).toString()
    }
    val lines=body.lines().map(::inline)
    return when(style){"list"->"<ul>"+lines.filter{it.isNotBlank()}.joinToString(""){"<li>$it</li>"}+"</ul>";"bold"->lines.joinToString(""){"<p><strong>$it</strong></p>"};else->lines.joinToString(""){"<p>$it</p>"}}
}
