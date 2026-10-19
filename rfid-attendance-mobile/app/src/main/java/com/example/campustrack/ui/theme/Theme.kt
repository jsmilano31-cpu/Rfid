package com.example.campustrack.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.*
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.ui.unit.dp
import com.example.campustrack.data.AppPreferences

private val LightColors = lightColorScheme(
    primary = CampusBlue, onPrimary = Color.White,
    primaryContainer = Color(0xFFEAF1FF), onPrimaryContainer = Color(0xFF15479A),
    secondary = CampusGreen, secondaryContainer = Color(0xFFE9F7EF),
    background = CampusCanvas, onBackground = CampusInk,
    surface = Color.White, onSurface = CampusInk,
    surfaceVariant = Color(0xFFEDF1F7), onSurfaceVariant = CampusMuted,
    outline = Color(0xFF8A94A6), outlineVariant = CampusLine,
    error = CampusRed
)
private val DarkColors = darkColorScheme(
    primary = Color(0xFFA8C7FF), onPrimary = Color(0xFF002F6C),
    primaryContainer = Color(0xFF163965), onPrimaryContainer = Color(0xFFD6E5FF),
    secondary = Color(0xFF8AD9AF), secondaryContainer = Color(0xFF173D2F),
    background = Color(0xFF10151E), onBackground = Color(0xFFE6EBF4),
    surface = Color(0xFF19212D), onSurface = Color(0xFFE6EBF4),
    surfaceVariant = Color(0xFF253143), onSurfaceVariant = Color(0xFFAFBACB),
    outlineVariant = Color(0xFF303D50), error = Color(0xFFFFB4AB)
)

@Composable
fun CampusTrackTheme(
    preferences: AppPreferences = AppPreferences(),
    darkTheme: Boolean? = null,
    content: @Composable () -> Unit
) {
    val isDark = darkTheme
        ?: (preferences.themeMode == "dark" ||
                (preferences.themeMode == "system" && isSystemInDarkTheme()))

    val accent = when (preferences.accentTheme) {
        "green" -> Color(0xFF087A55)
        "purple" -> Color(0xFF6941C6)
        "orange" -> Color(0xFFC45118)
        else -> CampusBlue
    }

    val base = if (isDark) DarkColors else LightColors

    MaterialTheme(
        colorScheme = base.copy(
            primary = if (isDark) {
                accent.copy(
                    red = (accent.red + .28f).coerceAtMost(1f),
                    green = (accent.green + .28f).coerceAtMost(1f),
                    blue = (accent.blue + .28f).coerceAtMost(1f)
                )
            } else accent
        ),
        content = content
    )
}
