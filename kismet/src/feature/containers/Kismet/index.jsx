/*
 * Project: Frieren Framework
 * Copyright (C) 2026 ZeroDay Advisory™ <nullbytesX00@icloud.com>
 * SPDX-License-Identifier: LGPL-3.0-only
 * More info at: https://github.com/xchwarze/frieren
 */
import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'react-toastify';
import { fetchPost } from '@common/services/fetchService.js';
import PanelCard from '@common/components/PanelCard';
import PanelStack from '@common/components/PanelCard/PanelStack';

const STATUS_QUERY_KEY = ['kismet', 'moduleStatus'];
const POLL_INTERVAL_MS = 5000;

/**
 * Kismet module panel: start/stop a capture on a chosen interface, show whether
 * it's running, and link out to Kismet's own native web UI (port 2501) for the
 * real device-tracking experience rather than re-implementing its REST client here.
 */
const KismetPage = () => {
    const queryClient = useQueryClient();
    const [selectedInterface, setSelectedInterface] = useState('');

    const { data: status, isLoading } = useQuery({
        queryKey: STATUS_QUERY_KEY,
        queryFn: () => fetchPost({ module: 'kismet', action: 'moduleStatus' }),
        refetchInterval: POLL_INTERVAL_MS,
    });

    const startMutation = useMutation({
        mutationFn: () => fetchPost({ module: 'kismet', action: 'startCapture', interface: selectedInterface }),
        onSuccess: () => {
            toast.success('Kismet started');
            queryClient.invalidateQueries({ queryKey: STATUS_QUERY_KEY });
        },
        onError: (error) => toast.error(error.message),
    });

    const stopMutation = useMutation({
        mutationFn: () => fetchPost({ module: 'kismet', action: 'stopCapture' }),
        onSuccess: () => {
            toast.success('Kismet stopped');
            queryClient.invalidateQueries({ queryKey: STATUS_QUERY_KEY });
        },
        onError: (error) => toast.error(error.message),
    });

    if (isLoading) {
        return <PanelCard title="Kismet">Loading...</PanelCard>;
    }

    if (!status?.hasDependencies) {
        return (
            <PanelCard title="Kismet">
                <p className="text-danger mb-0">
                    Kismet binary not found. This module ships its own binary under
                    <code> bin/kismet</code> — reinstall the module if it's missing.
                </p>
            </PanelCard>
        );
    }

    const isRunning = status.isRunning;
    const interfaces = status.interfaces ?? [];
    const webUiUrl = `http://${window.location.hostname}:${status.webUiPort}/`;

    return (
        <PanelStack>
            <PanelCard title="Kismet">
                <p>
                    Status:{' '}
                    <strong className={isRunning ? 'text-success' : 'text-secondary'}>
                        {isRunning ? 'Running' : 'Stopped'}
                    </strong>
                </p>

                {!isRunning && (
                    <div className="d-flex gap-2 align-items-center mb-3">
                        <select
                            className="form-select"
                            value={selectedInterface}
                            onChange={(e) => setSelectedInterface(e.target.value)}
                        >
                            <option value="">Select capture interface...</option>
                            {interfaces.map((ifname) => (
                                <option key={ifname} value={ifname}>{ifname}</option>
                            ))}
                        </select>
                        <button
                            className="btn btn-primary text-nowrap"
                            disabled={!selectedInterface || startMutation.isPending}
                            onClick={() => startMutation.mutate()}
                        >
                            Start
                        </button>
                    </div>
                )}

                {isRunning && (
                    <div className="d-flex gap-2 mb-3">
                        <button
                            className="btn btn-danger"
                            disabled={stopMutation.isPending}
                            onClick={() => stopMutation.mutate()}
                        >
                            Stop
                        </button>
                        <a className="btn btn-outline-secondary" href={webUiUrl} target="_blank" rel="noreferrer">
                            Open Kismet Web UI
                        </a>
                    </div>
                )}

                <p className="text-muted small mb-0">
                    Kismet runs its own web UI/REST API on port {status.webUiPort}. This panel only
                    manages the process (interface + start/stop) — use the native UI above for live
                    device tracking, channels, and alerts.
                </p>
            </PanelCard>
        </PanelStack>
    );
};

export default KismetPage;
