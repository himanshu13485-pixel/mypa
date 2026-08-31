package app.netvork;

import android.app.NotificationManager;
import android.app.PictureInPictureParams;
import android.content.Intent;
import android.os.Build;
import android.os.Bundle;
import android.util.Rational;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

    /** See NetvorkMessagingService.openApp for why arrival cancels a notification. */
    public static final String EXTRA_CANCEL_NOTIFICATION = "netvork_cancel_notification";

    /**
     * Whether the app is on screen right now.
     *
     * Read by NetvorkMessagingService when a call arrives: an app in the
     * foreground already has a live websocket and rings with its own in-app
     * UI, so drawing the system ringing notification on top of it would ring
     * a person twice for one call.
     */
    public static volatile boolean inForeground = false;

    @Override
    public void onCreate(Bundle savedInstanceState) {
        // Before super: the bridge is built there, and a plugin registered
        // afterwards is not in it.
        registerPlugin(CallServicePlugin.class);
        super.onCreate(savedInstanceState);
        // Before anything can be posted to them. A channel that does not
        // exist when a push lands is silently downgraded to the FCM
        // library's own fallback, losing both the sound and the heads-up.
        NotificationChannels.ensure(this);
        cancelNamedNotification(getIntent());
    }

    @Override
    public void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        cancelNamedNotification(intent);
    }

    /**
     * Answer was pressed, or the body tapped: the ringing notification's job
     * is done, but an action button's tap does not auto-cancel and the ring
     * is FLAG_INSISTENT — left alone it would go on ringing over the call it
     * just opened. The trampoline ban rules out cancelling en route, so the
     * destination does it.
     */
    private void cancelNamedNotification(Intent intent) {
        int id = intent == null ? 0 : intent.getIntExtra(EXTRA_CANCEL_NOTIFICATION, 0);
        if (id != 0) {
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) {
                manager.cancel(id);
            }
        }
    }

    /**
     * Pressing home during a call floats the app instead of hiding it.
     *
     * onUserLeaveHint fires when a person deliberately leaves — home, or the
     * recents switcher — and not when something else takes the screen, which
     * is exactly the distinction wanted: an incoming system call should not
     * shrink our call into a corner.
     *
     * Picture-in-picture keeps the activity visible and, crucially, still
     * counted as foreground, so the camera keeps running too. The foreground
     * service covers the microphone; this covers the video, and gives back
     * the floating window that a phone call has.
     */
    @Override
    public void onUserLeaveHint() {
        super.onUserLeaveHint();
        if (!CallForegroundService.callActive || Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }
        try {
            enterPictureInPictureMode(new PictureInPictureParams.Builder()
                // Portrait-ish, which suits a call face and a phone camera. A
                // ratio outside roughly 1:2.39–2.39:1 is refused outright.
                .setAspectRatio(new Rational(9, 16))
                .build());
        } catch (Exception e) {
            // Refused — the device disallows it, or the user has turned it
            // off for this app. Leaving is then just leaving, as before.
        }
    }

    @Override
    public void onResume() {
        super.onResume();
        inForeground = true;
    }

    @Override
    public void onPause() {
        super.onPause();
        inForeground = false;
    }
}
