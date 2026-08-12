package app.netvork;

import android.content.Intent;
import android.os.Build;

import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Lets the web app start and stop the call foreground service.
 *
 * The web app is the only thing that knows whether a call is running, so it
 * owns the decision; this is the doorway. Both methods are safe to call more
 * than once — starting a running service just refreshes its notification, and
 * stopping a stopped one does nothing — because the web app calls them from
 * effects, and an effect that must fire exactly once is a bug waiting.
 */
@CapacitorPlugin(name = "CallService")
public class CallServicePlugin extends Plugin {

    @PluginMethod
    public void start(PluginCall call) {
        Intent intent = new Intent(getContext(), CallForegroundService.class);
        intent.putExtra(CallForegroundService.EXTRA_LABEL, call.getString("label"));
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                getContext().startForegroundService(intent);
            } else {
                getContext().startService(intent);
            }
            call.resolve();
        } catch (Exception e) {
            /*
             * Android 12+ refuses a foreground service started from the
             * background, and Android 14 is stricter again. Rejecting rather
             * than throwing keeps a refusal from taking the call down with it:
             * without the service the microphone is lost on backgrounding,
             * which is worse than it was but is not worse than no call.
             */
            call.reject("could not start the call service: " + e.getMessage());
        }
    }

    @PluginMethod
    public void stop(PluginCall call) {
        getContext().stopService(new Intent(getContext(), CallForegroundService.class));
        call.resolve();
    }
}
