package com.demo.notifications;

import java.time.Instant;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.CopyOnWriteArrayList;

import org.springframework.http.HttpStatus;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PostMapping;
import org.springframework.web.bind.annotation.RequestBody;
import org.springframework.web.bind.annotation.RequestMapping;
import org.springframework.web.bind.annotation.RequestParam;
import org.springframework.web.bind.annotation.RestController;

@RestController
@RequestMapping("/v1/notifications")
public class NotificationController {

    private static final Set<String> ALLOWED_TYPES = Set.of("warehouse", "courier", "customer");

    private final List<Notification> store = new CopyOnWriteArrayList<>();

    @GetMapping("/health")
    public Map<String, String> health() {
        return Map.of("status", "ok", "service", "notification-service");
    }

    @PostMapping
    public ResponseEntity<?> create(@RequestBody NotificationRequest request) {
        if (request == null || isBlank(request.orderId()) || isBlank(request.type())) {
            return ResponseEntity.badRequest().body(Map.of(
                    "error", "INVALID_REQUEST",
                    "message", "type y orderId son obligatorios"
            ));
        }

        String type = request.type().trim().toLowerCase();
        if (!ALLOWED_TYPES.contains(type)) {
            return ResponseEntity.badRequest().body(Map.of(
                    "error", "INVALID_TYPE",
                    "message", "type debe ser warehouse, courier o customer"
            ));
        }

        Notification notification = new Notification(
                UUID.randomUUID().toString(),
                type,
                request.orderId(),
                request.payload() == null ? Map.of() : request.payload(),
                Instant.now()
        );
        store.add(notification);

        return ResponseEntity.status(HttpStatus.CREATED).body(notification);
    }

    @GetMapping
    public Map<String, Object> list(@RequestParam(required = false) String orderId) {
        List<Notification> notifications = store.stream()
                .filter(item -> orderId == null || orderId.isBlank() || item.orderId().equals(orderId))
                .toList();

        return Map.of("notifications", new ArrayList<>(notifications));
    }

    private static boolean isBlank(String value) {
        return value == null || value.isBlank();
    }
}
