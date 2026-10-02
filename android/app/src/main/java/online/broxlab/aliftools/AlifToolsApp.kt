package online.broxlab.aliftools

import android.app.Application
import online.broxlab.aliftools.data.AppContainer

/**
 * Manual DI root (audit §6.2: "Hilt-lite manual DI") — one container, built
 * once, handed to activities via the application instance.
 */
class AlifToolsApp : Application() {
    lateinit var container: AppContainer
        private set

    override fun onCreate() {
        super.onCreate()
        container = AppContainer(this)
    }
}
