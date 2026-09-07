package com.demo.notifications;

import java.time.Instant;
import java.util.Map;

public record Notification(
        String id,
        String type,
        String orderId,
        Map<String, Object> payload,
        Instant createdAt
) {
}
