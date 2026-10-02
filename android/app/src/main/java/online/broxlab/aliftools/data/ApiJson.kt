package online.broxlab.aliftools.data

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.decodeFromJsonElement

/** One Json instance shared by the converter factory and the decode helpers. */
internal object AppJson {
    val json: Json = Json {
        ignoreUnknownKeys = true
        explicitNulls = false
        coerceInputValues = true
    }
}

/** Decode the backend's `data` payload into a typed DTO; null on mismatch. */
inline fun <reified T> decodeJson(element: JsonElement?): T? {
    if (element == null || element is JsonNull) return null
    return runCatching {
        AppJson.json.decodeFromJsonElement(element)
    }.getOrNull()
}
