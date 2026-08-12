package app.netvork;

import com.getcapacitor.BridgeActivity;

public class MainActivity extends BridgeActivity {

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
