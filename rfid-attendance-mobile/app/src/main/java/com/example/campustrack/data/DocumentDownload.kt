package com.example.campustrack.data

import android.content.ContentResolver
import android.net.Uri
import android.provider.DocumentsContract
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.withContext

/** Only removes the newly created document if its download did not finish successfully. */
internal suspend fun ApiClient.saveDocument(resolver: ContentResolver, uri: Uri, path: String) {
    var complete = false
    try {
        withContext(Dispatchers.IO) {
            resolver.openOutputStream(uri, "wt")?.use { downloadTo(path, it) }
                ?: error("Could not open the selected file.")
        }
        complete = true
    } finally {
        if (!complete) withContext(NonCancellable + Dispatchers.IO) {
            runCatching { DocumentsContract.deleteDocument(resolver, uri) }
        }
    }
}
