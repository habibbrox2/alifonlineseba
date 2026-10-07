package com.aliftools.admin.di

import com.aliftools.admin.data.local.TokenStorage
import dagger.Module
import dagger.Provides
import dagger.hilt.InstallIn
import dagger.hilt.components.SingletonComponent
import javax.inject.Singleton

@Module
@InstallIn(SingletonComponent::class)
object LocalDataModule {

    @Provides
    @Singleton
    fun provideTokenStorage(androidApp: com.aliftools.admin.AlifAdminApplication): TokenStorage {
        return TokenStorage(androidApp.applicationContext)
    }
}
