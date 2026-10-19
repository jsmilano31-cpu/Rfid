package com.example.campustrack.ui

import androidx.compose.foundation.layout.*
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.outlined.*
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.animation.animateColorAsState
import androidx.compose.animation.core.*
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.luminance
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.text.style.TextOverflow
import dev.chrisbanes.haze.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp

internal data class PortalDestination(val route: String, val label: String, val icon: ImageVector)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
internal fun PortalTopBar(initials: String, showBack: Boolean, onBack: () -> Unit, onProfile: () -> Unit, actions: @Composable RowScope.() -> Unit) {
    TopAppBar(
        title = { Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Icon(Icons.Outlined.Sensors, "CampusTrack", tint = MaterialTheme.colorScheme.primary)
            if(androidx.compose.ui.platform.LocalConfiguration.current.screenWidthDp>=360)Text("CampusTrack", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold,maxLines=1)
        } },
        navigationIcon = {
            if (showBack) IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, "Back") }
            else Surface(onClick = onProfile, modifier = Modifier.padding(start = 16.dp).size(48.dp), shape = CircleShape, color = MaterialTheme.colorScheme.primaryContainer) {
                Box(contentAlignment = Alignment.Center) { Text(initials, color = MaterialTheme.colorScheme.onPrimaryContainer, style = MaterialTheme.typography.labelLarge) }
            }
        }, actions = actions,
        colors = TopAppBarDefaults.topAppBarColors(containerColor = MaterialTheme.colorScheme.background)
    )
}

@Composable
internal fun PortalStatusRow(role: String, loading: Boolean, onRefresh: () -> Unit) {
    Row(Modifier.fillMaxWidth().padding(horizontal = 20.dp), verticalAlignment = Alignment.CenterVertically) {
        Text("${role.uppercase()} PORTAL", Modifier.weight(1f), style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        Text("Campus account", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.onSurfaceVariant)
        IconButton(onClick = onRefresh, enabled = !loading, modifier = Modifier.size(48.dp)) { Icon(Icons.Outlined.Refresh, "Refresh records", Modifier.size(18.dp)) }
    }
}

// Visual basis: elyesmansour/compose-floating-tab-bar (floating capsule + glass effect).
// Custom layout keeps CampusTrack's AI action centered and preserves tab accessibility.
internal val LocalPortalBottomInset = staticCompositionLocalOf { 0.dp }
private val LocalPortalGlass = staticCompositionLocalOf<HazeState?> { null }

@Composable
internal fun PortalScaffold(
    topBar: @Composable () -> Unit,
    bottomBar: @Composable () -> Unit,
    snackbarHost: @Composable () -> Unit = {},
    content: @Composable (PaddingValues) -> Unit
) {
    val glass = rememberHazeState()
    CompositionLocalProvider(LocalPortalGlass provides glass) {
        Scaffold(topBar = topBar, bottomBar = bottomBar, snackbarHost = snackbarHost) { padding ->
            // Content scrolls behind the dock. Lists reserve its actual measured inset at their end.
            CompositionLocalProvider(LocalPortalBottomInset provides padding.calculateBottomPadding()) {
                Box(Modifier.fillMaxSize().hazeSource(glass)) {
                    content(PaddingValues(top = padding.calculateTopPadding()))
                }
            }
        }
    }
}

@Composable
internal fun PortalBottomBar(destinations: List<PortalDestination>, selected: String, enabled: Boolean, onNavigate: (String) -> Unit, onAssistant: () -> Unit, assistantLabel: String = "Open Campus Assistant", assistantIsSearch: Boolean = false, assistantEnabled: Boolean = true) {
    val reduceMotion = com.example.campustrack.data.LocalAppPreferences.current.value.reducedMotion
    val colors = MaterialTheme.colorScheme
    val dark = colors.surface.luminance() < .4f
    val glass = LocalPortalGlass.current
    val shape = CircleShape
    val glassStyle = HazeStyle(
        backgroundColor = colors.background,
        tint = HazeTint(colors.surface.copy(alpha = if (dark) .76f else .62f)),
        blurRadius = 24.dp, noiseFactor = .02f,
        fallbackTint = HazeTint(colors.surface.copy(alpha = .97f))
    )
    val rim = Brush.verticalGradient(listOf(Color.White.copy(alpha = if (dark) .32f else .95f), colors.outlineVariant.copy(alpha = .25f), Color.White.copy(alpha = if (dark) .10f else .65f)))
    Box(Modifier.fillMaxWidth().windowInsetsPadding(WindowInsets.navigationBars.only(WindowInsetsSides.Horizontal + WindowInsetsSides.Bottom)).padding(horizontal = 12.dp, vertical = 10.dp).testTag("portal/navigation"), contentAlignment = Alignment.Center) {
        BoxWithConstraints(Modifier.widthIn(max = 560.dp).fillMaxWidth().height(80.dp)
            .shadow(16.dp, shape, clip = false, ambientColor = Color(0xFF1B3558).copy(alpha = .18f), spotColor = Color(0xFF1B3558).copy(alpha = .20f))
            .clip(shape)
            .then(if (glass != null) Modifier.hazeEffect(glass, glassStyle) else Modifier.background(colors.surface.copy(alpha = .96f)))
            .background(Brush.verticalGradient(listOf(Color.White.copy(alpha = if (dark) .06f else .22f), Color.Transparent, colors.primary.copy(alpha = .035f))))
            .border(1.dp, rim, shape)
            .padding(6.dp)
            .selectableGroup()) {
            val assistantSlot = (destinations.size + 1) / 2
            val cell = maxWidth / (destinations.size + 1)
            val activeIndex = destinations.indexOfFirst { it.route == selected }
            val activeSlot = if (activeIndex >= assistantSlot) activeIndex + 1 else activeIndex
            val indicatorX by animateDpAsState(cell * activeSlot.coerceAtLeast(0), if (reduceMotion) snap() else spring(dampingRatio = .82f, stiffness = 420f), label = "Glass selection slide")
            if (activeIndex >= 0) Box(Modifier.offset(x = indicatorX).width(cell).fillMaxHeight().padding(horizontal = 2.dp, vertical = 3.dp)
                .clip(shape)
                .background(Brush.verticalGradient(listOf(colors.primary.copy(alpha = if (dark) .24f else .13f), colors.primary.copy(alpha = .06f))))
                .border(.8.dp, Color.White.copy(alpha = if (dark) .16f else .72f), shape))
            Row(Modifier.fillMaxSize(), verticalAlignment = Alignment.CenterVertically) {
                destinations.forEachIndexed { index, destination ->
                    if (index == assistantSlot) {
                        Box(Modifier.weight(1f), contentAlignment = Alignment.Center) { GlassAssistantButton(onAssistant, assistantLabel, assistantIsSearch, assistantEnabled) }
                    }
                    val active = selected == destination.route
                    val tint by animateColorAsState(if (active) colors.primary else colors.onSurfaceVariant, tween(if(reduceMotion)0 else 180), label = "Glass tab tint")
                    val interaction = remember { MutableInteractionSource() }
                    val pressed by interaction.collectIsPressedAsState()
                    val scale by animateFloatAsState(if (pressed) .90f else 1f, if(reduceMotion)snap()else spring(stiffness = 500f), label = "Glass tab press")
                    Column(Modifier.weight(1f).fillMaxHeight().clip(shape)
                        .testTag("nav/${destination.route}")
                        .selectable(active, enabled = enabled, role = Role.Tab, interactionSource = interaction, indication = null, onClick = { onNavigate(destination.route) })
                        .graphicsLayer { scaleX = scale; scaleY = scale; alpha = if (enabled) 1f else .45f },
                        verticalArrangement = Arrangement.Center, horizontalAlignment = Alignment.CenterHorizontally) {
                        Icon(destination.icon, null, Modifier.size(23.dp), tint = tint)
                        Spacer(Modifier.height(4.dp))
                        Text(destination.label, color = tint, style = MaterialTheme.typography.labelSmall.copy(fontSize = 10.sp), fontWeight = if (active) FontWeight.Bold else FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        }
    }
}

@Composable
private fun GlassAssistantButton(onClick: () -> Unit, label: String, search: Boolean, enabled: Boolean) {
    val interaction = remember { MutableInteractionSource() }
    val pressed by interaction.collectIsPressedAsState()
    val scale by animateFloatAsState(if (pressed) .90f else 1f, spring(dampingRatio = .65f, stiffness = 450f), label = "AI lens press")
    val dark = MaterialTheme.colorScheme.surface.luminance() < .4f
    Surface(onClick = onClick, enabled = enabled, interactionSource = interaction, shape = CircleShape, color = Color.Transparent,
        modifier = Modifier.size(56.dp).graphicsLayer { scaleX = scale; scaleY = scale }
            .shadow(6.dp, CircleShape, clip = false)
            .background(Brush.linearGradient(if (dark) listOf(Color(0xFFB2D9FF), Color(0xFF759EFF), Color(0xFF9D94F0)) else listOf(Color(0xFF73B5FF), Color(0xFF2867DB), Color(0xFF555AD4))), CircleShape)
            .border(1.dp, Brush.verticalGradient(listOf(Color.White.copy(alpha = .85f), Color.White.copy(alpha = .12f))), CircleShape)) {
        Box(Modifier.fillMaxSize().background(Brush.radialGradient(listOf(Color.White.copy(alpha = .25f), Color.Transparent))), contentAlignment = Alignment.Center) {
            Icon(if(search)Icons.Outlined.Search else Icons.Outlined.AutoAwesome, label, Modifier.size(26.dp), tint = if (dark) Color(0xFF152C62) else Color.White)
        }
    }
}
