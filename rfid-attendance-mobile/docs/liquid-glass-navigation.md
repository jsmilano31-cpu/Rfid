# Liquid glass navigation

The shared student/teacher dock takes its visual basis from https://github.com/elyesmansour/compose-floating-tab-bar: a floating capsule, independent primary action, animated selection and a blurred backdrop. CampusTrack uses its own Compose layout to retain the centered assistant, explicit tab semantics, existing navigation callbacks and disabled states. No upstream source is copied or vendored.

Backdrop rendering uses Chris Banes' Apache-2.0 Haze library. The dock adds a subtle gradient rim, translucent surface, spring-driven selection pill and press feedback. The AI lens stays inside measured bounds. This simulates the glass appearance; it does not implement Apple's optical refraction renderer.

PortalScaffold provides one Haze source per role and the measured bottom inset. Shared pagePadding reserves that inset at the end of scrolling content, allowing content to pass behind the dock while keeping final actions reachable. Navigation-bar system insets are applied once by the dock. The dock is capped at 560dp on wide screens. Haze supplies a readable opaque fallback when blur is unsupported.

Keep new portal lists on pagePadding. Keep overlays and dialogs outside the backdrop source. PortalChromeTest checks the full assistant bounds and activation in both themes and roles.
