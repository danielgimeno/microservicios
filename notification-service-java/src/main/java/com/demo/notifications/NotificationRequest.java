package com.demo.notifications;

import java.util.Map;

public record NotificationRequest(
        String type,
        String orderId,
        Map<String, Object> payload
) {
}
