package app.netvork;

import android.app.NotificationManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.util.Log;

import java.net.HttpURLConnection;
import java.net.URL;

/**
 * The Decline button, which must work without opening the app.
 *
 * There is no auth token out here — it lives in the webview's storage, and
 * the whole point of Decline is not waking the webview. The URL it posts to
 * is signed server-side over the call, the callee and a one-minute expiry, so
 * the URL itself is the authorisation: pressing the button is the proof.
 *
 * Only URLs on our own host are honoured. The value rides through an FCM
 * payload, and a receiver that POSTs to whatever address it is handed would
 * be a request forger with a system-UI button.
 */
public class DeclineReceiver extends BroadcastReceiver {

    public static final String EXTRA_URL = "decline_url";
    public static final String EXTRA_NOTIFICATION_ID = "notification_id";

    @Override
    public void onReceive(Context context, Intent intent) {
        String url = intent.getStringExtra(EXTRA_URL);
        int id = intent.getIntExtra(EXTRA_NOTIFICATION_ID, 0);

        // Silence first — the press must feel instant even on bad signal.
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        if (manager != null) {
            manager.cancel(id);
        }

        if (url == null || !url.startsWith("https://netvork.app/")) {
            return;
        }

        final PendingResult result = goAsync();
        new Thread(() -> {
            try {
                HttpURLConnection conn = (HttpURLConnection) new URL(url).openConnection();
                conn.setRequestMethod("POST");
                conn.setConnectTimeout(5000);
                conn.setReadTimeout(5000);
                conn.getResponseCode();
                conn.disconnect();
            } catch (Exception e) {
                // Best-effort by design: the ring is silenced locally either
                // way, and the caller's side times out on its own.
                Log.w("NetvorkDecline", "decline call failed: " + e.getMessage());
            } finally {
                result.finish();
            }
        }).start();
    }
}
