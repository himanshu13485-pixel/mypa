package app.netvork;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.media.AudioAttributes;
import android.net.Uri;
import android.os.Build;
import androidx.annotation.NonNull;
import androidx.core.app.NotificationCompat;
import com.google.firebase.messaging.RemoteMessage;

import java.util.Map;

/**
 * The native half of ringing.
 *
 * Calls arrive as data-only FCM messages, and Android wakes this service for
 * those even when the app process is dead — which is the entire point. A
 * notification drawn by FCM itself (the "notification block" path every other
 * kind of push here uses) is reliable but plain: one big tappable surface
 * that answers the call, which is how "I only wanted to silence it" came to
 * mean "I picked it up". Building the notification in code buys the three
 * things that make it behave like a phone:
 *
 *   Answer and Decline as separate buttons, with the body tap doing neither —
 *   it just opens the calls screen, where the ongoing-call banner offers the
 *   same choice at leisure.
 *
 *   FLAG_INSISTENT, which loops the channel's ringtone until the notification
 *   is dismissed or times out, instead of playing it once.
 *
 *   A full-screen intent, so on a locked phone the call can take the screen
 *   the way a phone call does. (Newer Android reserves this for apps the user
 *   has blessed; where it is refused, the heads-up notification still shows.)
 *
 * Everything not a call is handed straight to Capacitor's own service, so the
 * plugin's behaviour — token rotation included, via the inherited
 * onNewToken — is exactly what it was.
 */
public class NetvorkMessagingService extends com.capacitorjs.plugins.pushnotifications.MessagingService {

    /** Must match what the web bundle creates and the server addresses. */
    private static final String CALL_CHANNEL = "calls2";

    @Override
    public void onMessageReceived(@NonNull RemoteMessage remoteMessage) {
        Map<String, String> data = remoteMessage.getData();
        if (!"call".equals(data.get("kind"))) {
            super.onMessageReceived(remoteMessage);
            return;
        }

        // On screen: the websocket is live and the in-app UI is already
        // ringing. A system notification on top would ring twice.
        if (MainActivity.inForeground) {
            return;
        }

        String callUuid = data.get("call_uuid");
        int id = callUuid != null ? callUuid.hashCode() : 1;

        ensureChannel();

        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, CALL_CHANNEL)
            .setSmallIcon(getApplicationInfo().icon)
            .setContentTitle(orDefault(data.get("title"), "Netvork"))
            .setContentText(orDefault(data.get("body"), "Incoming call"))
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            // The ring is over well before this; a stale ringing notification
            // for a call nobody can answer any more is worse than none.
            .setTimeoutAfter(45_000)
            .setAutoCancel(true);

        // Body tap: look at the call, decide there. Deliberately NOT answer.
        builder.setContentIntent(openApp(id, "/calls"));

        String joinUrl = data.get("url");
        if (joinUrl != null) {
            builder.addAction(0, "Answer", openApp(id + 1, joinUrl));
        }

        String declineUrl = data.get("decline_url");
        if (declineUrl != null) {
            Intent decline = new Intent(this, DeclineReceiver.class)
                .putExtra(DeclineReceiver.EXTRA_URL, declineUrl)
                .putExtra(DeclineReceiver.EXTRA_NOTIFICATION_ID, id);
            builder.addAction(0, "Decline", PendingIntent.getBroadcast(
                this, id + 2, decline,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE));
        }

        // A locked phone gets the call over the lock screen, where the
        // platform allows it.
        builder.setFullScreenIntent(openApp(id + 3, "/calls"), true);

        Notification notification = builder.build();
        // Loop the ringtone until answered, declined, dismissed or timed out —
        // the difference between a ring and a chirp with ambitions.
        notification.flags |= Notification.FLAG_INSISTENT;

        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) {
            manager.notify(id, notification);
        }
    }

    /** An intent into the webview at a path, cold start included. */
    private PendingIntent openApp(int requestCode, String path) {
        Intent intent = new Intent(Intent.ACTION_VIEW,
            Uri.parse("https://netvork.app" + path), this, MainActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);

        return PendingIntent.getActivity(this, requestCode, intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /**
     * The channel the web bundle normally creates, recreated here if missing.
     *
     * Belt and braces for one cold-start ordering: a call arriving after
     * install but before the app was ever opened would otherwise address a
     * channel that does not exist, and Android drops those silently.
     */
    private void ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager == null || manager.getNotificationChannel(CALL_CHANNEL) != null) {
            return;
        }

        NotificationChannel channel = new NotificationChannel(
            CALL_CHANNEL, "Incoming calls", NotificationManager.IMPORTANCE_HIGH);
        channel.setDescription("Rings when somebody calls you");
        channel.enableVibration(true);
        channel.setSound(
            Uri.parse("android.resource://" + getPackageName() + "/raw/ringtone"),
            new AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build());
        manager.createNotificationChannel(channel);
    }

    private static String orDefault(String value, String fallback) {
        return value == null || value.isEmpty() ? fallback : value;
    }
}
