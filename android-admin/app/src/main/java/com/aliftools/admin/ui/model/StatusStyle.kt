package com.aliftools.admin.ui.model

/** Visual family for a status pill. Colour alone never carries the meaning. */
enum class StatusTone { Warning, Info, Success, Danger, Neutral }

data class StatusStyle(
    val label: String,
    val tone: StatusTone,
    val inFlight: Boolean,
)

/**
 * The Android mirror of App\Service\StatusPresenter.
 *
 * This is the only place a status becomes a Bengali label and a tone. A screen
 * that maps status itself drifts from the other screens and from the web.
 */
object Statuses {

    fun of(status: String?): StatusStyle {
        val key = status?.trim()?.lowercase().orEmpty()
        return when (key) {
            "pending" -> StatusStyle("পেন্ডিং", StatusTone.Warning, inFlight = true)
            "processing" -> StatusStyle("প্রসেসিং", StatusTone.Info, inFlight = true)
            "review" -> StatusStyle("যাচাই ধরা হয়েছে", StatusTone.Info, inFlight = true)
            "completed" -> StatusStyle("সম্পন্ন", StatusTone.Success, false)
            "approved" -> StatusStyle("অনুমোদিত", StatusTone.Success, false)
            "paid" -> StatusStyle("পরিশোধিত", StatusTone.Success, false)
            "active" -> StatusStyle("সক্রিয়", StatusTone.Success, false)
            "success" -> StatusStyle("সফল", StatusTone.Success, false)
            "failed" -> StatusStyle("ব্যর্থ", StatusTone.Danger, false)
            "cancelled" -> StatusStyle("বাতিল", StatusTone.Danger, false)
            "rejected" -> StatusStyle("বাতিলকৃত", StatusTone.Danger, false)
            "deleted" -> StatusStyle("মুছে ফেলা", StatusTone.Danger, false)
            "danger" -> StatusStyle("অ্যালার্ট", StatusTone.Danger, false)
            "warning" -> StatusStyle("সতর্কতা", StatusTone.Warning, false)
            "info" -> StatusStyle("তথ্য", StatusTone.Info, false)
            "disabled", "inactive" -> StatusStyle("নিষ্ক্রিয়", StatusTone.Neutral, false)
            "" -> StatusStyle("—", StatusTone.Neutral, false)
            else -> StatusStyle(status.orEmpty(), StatusTone.Neutral, false)
        }
    }
}
