<?php
/*
 * Project: Frieren Framework
 * Copyright (C) 2026 ZeroDay Advisory™ <nullbytesX00@icloud.com>
 * SPDX-License-Identifier: LGPL-3.0-only
 * More info at: https://github.com/xchwarze/frieren
 */

namespace frieren\modules\mdk4;

/**
 * Helper class for the OpenWrt platform.
 */
class ModuleOpenWrtHelper
{
    /**
     * Lists kernel network interfaces (wired + wireless), for the interface picker.
     * mdk4 needs a monitor-mode interface specifically -- the picker shows all
     * interfaces, but the frontend hints that it must be one in monitor mode.
     *
     * @return string[] Interface names, or empty array on failure.
     */
    public static function getNetworkInterfaces()
    {
        $interfaces = scandir('/sys/class/net/');
        if ($interfaces === false) {
            return [];
        }

        return array_values(array_diff($interfaces, ['.', '..']));
    }
}
