package app.netvork;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.net.Uri;
import android.os.Build;
import android.os.IBinder;
import androidx.core.app.NotificationCompat;

/**
 * Keeps a call alive while the app is in the background.
 *
 * Since Android 9 the operating system cuts microphone access to any app that
 * is not in the foreground — so pressing home mid-call muted you, and nothing
 * in the web app could prevent it, because it is not the web app doing it. A
 * foreground service is the sanctioned way to say "this app is doing something
 * the person can see and asked for", and from Android 10 it must name the
 * reason: microphone. That declaration is what buys back the microphone.
 *
 * The ongoing notification is not decoration and cannot be hidden — it is the
 * bargain. Every calling app shows one for exactly this reason; WhatsApp's
 * "ongoing call" line is this same service.
 *
 * Deliberately dumb: it holds no call state and talks to nothing. The web app
 * knows when a call starts and ends and says so; anything cleverer would be a
 * second source of truth about whether you are in a call.
 */
public class CallForegroundService extends Service {

    public static final String EXTRA_LABEL = "label";
    private static final String CHANNEL = "ongoing_call";
    private static final int NOTIFICATION_ID = 4242;

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        String label = intent != null ? intent.getStringExtra(EXTRA_LABEL) : null;
        ensureChannel();

        Intent open = new Intent(Intent.ACTION_VIEW,
            Uri.parse("https://netvork.app/calls"), this, MainActivity.class);
        open.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);

        Notification notification = new NotificationCompat.Builder(this, CHANNEL)
            .setSmallIcon(getApplicationInfo().icon)
            .setContentTitle(label == null || label.isEmpty() ? "Ongoing call" : label)
            .setContentText("Tap to return to Netvork")
            .setCategory(NotificationCompat.CATEGORY_CALL)
            // Silent and unswipeable: this is a status line, not an alert. It
            // has already rung once and must not ring again for the duration.
            .setOngoing(true)
            .setSilent(true)
            .setContentIntent(PendingIntent.getActivity(this, 0, open,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE))
            .build();

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            // Naming the type is what actually keeps the microphone; from
            // Android 14 starting without it is an outright crash.
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE);
        } else {
            startForeground(NOTIFICATION_ID, notification);
        }

        // Not sticky: if Android kills this, the call is gone with it and
        // restarting an empty service would leave a notification for a call
        // that no longer exists.
        return START_NOT_STICKY;
    }

    private void ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager == null || manager.getNotificationChannel(CHANNEL) != null) {
            return;
        }
        NotificationChannel channel = new NotificationChannel(
            CHANNEL, "Ongoing call", NotificationManager.IMPORTANCE_LOW);
        channel.setDescription("Shown while a call or meeting is running");
        channel.setShowBadge(false);
        manager.createNotificationChannel(channel);
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }
}
