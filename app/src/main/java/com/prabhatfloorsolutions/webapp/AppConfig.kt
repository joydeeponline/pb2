package com.prabhatfloorsolutions.webapp

object AppConfig {
    const val BASE_URL = "https://www.prabhatfloor.com/"
    const val DASHBOARD_PATH = ""
    const val ACCEPTED_LOADS_PATH = "__none__"
    const val LOGOUT_PATH = ""

    val allowedHosts = setOf(
        "www.prabhatfloor.com",
        "prabhatfloor.com"
    )

    fun dashboardUrl(): String = BASE_URL + DASHBOARD_PATH
    fun acceptedLoadsUrl(): String = BASE_URL + ACCEPTED_LOADS_PATH
    fun logoutUrl(): String = BASE_URL + LOGOUT_PATH
}
