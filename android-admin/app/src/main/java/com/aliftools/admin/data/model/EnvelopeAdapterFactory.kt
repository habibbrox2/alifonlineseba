package com.aliftools.admin.data.model

import com.google.gson.Gson
import com.google.gson.JsonElement
import com.google.gson.TypeAdapter
import com.google.gson.TypeAdapterFactory
import com.google.gson.reflect.TypeToken
import com.google.gson.stream.JsonReader
import com.google.gson.stream.JsonToken
import com.google.gson.stream.JsonWriter
import java.lang.reflect.ParameterizedType
import java.lang.reflect.Type

/**
 * Reads the `{success, message, data, errors}` envelope without letting a
 * shape mismatch destroy the message the server sent.
 *
 * The PHP side is loose about `data`: Api::ok() puts an object in it, but
 * Api::fail() puts the *validation errors* there — which some actions build
 * as `{"field": ["msg"]}` and others as `["msg", …]`. A strict reflective
 * adapter throws on the array, the whole body fails to convert, and the user
 * would see "Expected BEGIN_OBJECT" instead of the Bengali reason the API
 * actually returned. Here `data` and `errors` are parsed as JsonElement
 * first and decoded to the target type best-effort; when that fails they
 * become null while `success`/`message` survive, which is exactly what the
 * repository needs to build a meaningful Result.failure.
 */
object EnvelopeAdapterFactory : TypeAdapterFactory {

    private val errorsType: Type = object : TypeToken<Map<String, List<String>>>() {}.type

    @Suppress("UNCHECKED_CAST")
    override fun <T> create(gson: Gson, type: TypeToken<T>): TypeAdapter<T>? {
        val raw: Type = type.type
        if (raw !is ParameterizedType || raw.rawType != ApiResponse::class.java) {
            return null
        }
        // T here is ApiResponse<dataType>; the adapter reads the envelope.
        return EnvelopeAdapter<Any?>(gson, raw.actualTypeArguments[0]).nullSafe() as TypeAdapter<T>
    }

    private class EnvelopeAdapter<T>(
        private val gson: Gson,
        private val dataType: Type,
    ) : TypeAdapter<ApiResponse<T>>() {

        override fun write(out: JsonWriter, value: ApiResponse<T>?) {
            if (value == null) {
                out.nullValue()
                return
            }
            out.beginObject()
            out.name("success").value(value.success)
            out.name("message").value(value.message)
            out.name("data")
            gson.toJson(value.data, dataType, out)
            out.name("errors")
            gson.toJson(value.errors, errorsType, out)
            out.endObject()
        }

        override fun read(`in`: JsonReader): ApiResponse<T> {
            var success = false
            var message: String? = null
            var data: T? = null
            var errors: Map<String, List<String>>? = null

            `in`.beginObject()
            while (`in`.hasNext()) {
                when (`in`.nextName()) {
                    "success" -> success = `in`.nextBoolean()
                    "message" -> message = readStringOrSkip(`in`)
                    "data" -> {
                        val element = gson.fromJson<JsonElement>(`in`, JsonElement::class.java)
                        data = decodeOrNull(element, dataType)
                    }
                    "errors" -> {
                        val element = gson.fromJson<JsonElement>(`in`, JsonElement::class.java)
                        errors = decodeOrNull(element, errorsType)
                    }
                    else -> `in`.skipValue()
                }
            }
            `in`.endObject()

            return ApiResponse(success, message, data, errors)
        }

        private fun readStringOrSkip(`in`: JsonReader): String? =
            if (`in`.peek() == JsonToken.NULL) {
                `in`.nextNull()
                null
            } else {
                `in`.nextString()
            }

        private fun <R> decodeOrNull(element: JsonElement?, type: Type): R? {
            if (element == null || element.isJsonNull) return null
            return try {
                @Suppress("UNCHECKED_CAST")
                gson.fromJson<R>(element, type)
            } catch (_: Exception) {
                // Shape mismatch (array where an object was typed, …):
                // drop the payload, keep the envelope's message.
                null
            }
        }
    }
}
