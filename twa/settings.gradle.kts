pluginManagement {
    repositories {
        google {
            content {
                includeGroupByRegex("com\\.android.*")
                includeGroupByRegex("com\\.google.*")
                includeGroupByRegex("androidx.*")
            }
        }
        mavenCentral()
        gradlePluginPortal()
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}

// Deliberately *not* "alif-tools": that root project name belongs to the
// native client in ../android. Two settings must not collide in the Gradle
// daemon cache or the IDE's recent-projects list.
rootProject.name = "alif-tools-twa"
include(":app")
