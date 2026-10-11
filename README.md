# humanOS

A customizable, cross-platform fitness and health management application that helps users track nutrition, workouts, physical activity, health metrics, and personal goals.

Unlike traditional fitness trackers, humanOS does not simply display statistics. It analyzes user data and provides personalized recommendations about what to eat, how to train, and what to improve each day.

> **Core question:** *What should I do today to get closer to my fitness goals?*

This repository contains the **backend API** (Laravel).

---

## Table of Contents

- [Core Features](#core-features)
- [Supported Platforms](#supported-platforms)
- [Technology Stack](#technology-stack)
- [Proposed Repository Structure](#proposed-repository-structure)
- [Initial MVP](#initial-mvp)
- [Later Phases](#later-phases)
- [Product Vision](#product-vision)

---

## Core Features

### 1. Personal Profile & Goals

Users enter age, weight, height, activity level, and fitness objectives. The app calculates personalized calorie and macronutrient targets for fat loss, muscle gain, or weight maintenance. Users can customize targets.

### 2. Nutrition & Meal Tracking

- Create custom foods with nutritional information per gram or serving.
- Use a shared food database maintained by administrators.
- Track calories, protein, carbohydrates, fats, and fiber.
- Create recipes and reusable meals.
- Log meals or plan them ahead of time.
- Monitor daily remaining calories and macronutrients.
- Track food prices and generate grocery lists.

### 3. Workout & Activity Tracking

- Create customizable workout routines.
- Record exercises, sets, repetitions, weights, and rest periods.
- Track walking, cycling, running, and other measurable activities.
- Record distance, duration, heart rate, and estimated calories burned.
- Review workout history and strength progression.

### 4. Health & Wearable Integration

Integrate optionally with Apple Health and Apple Watch, with manual entry as an alternative. Track steps, active calories, heart rate, sleep, weight, body fat, and workouts.

### 5. Smart Daily Dashboard

Display calories consumed and estimated burned, remaining calories relative to the user's target, protein/carbohydrate/fat progress, workouts, steps, sleep, recovery, and goal progress.

### 6. Intelligent Decision Engine

Combine nutrition, activity, health data, and goals to suggest practical next actions:

- Eat another 35 g of protein today.
- Choose a meal that fits remaining macros.
- Walk another 2,000 steps to reach an activity goal.
- Review calorie targets based on recent progress.
- Consider recovery before an intense workout.

Calorie guidance should estimate total daily energy expenditure (TDEE) and avoid automatically treating every exercise calorie as extra food allowance.

### 7. AI Personal Coach

Analyze longer-term patterns to identify inconsistent habits, weight plateaus, workout progression, sleep and recovery trends, and possible adjustments. Recommendations should explain their reasoning and avoid overstating what the data proves.

### 8. Progress & Analytics

- Weight and body measurement history.
- Progress photos.
- Strength progression charts.
- Nutrition consistency.
- Weekly and monthly reports.
- Personalized fitness insights.

### 9. Lifestyle Features

- Water intake tracking.
- Supplement reminders.
- Habit tracking.
- Recovery tracking.
- Meal preparation planning.
- Grocery budgeting.
- Daily achievement scores.

### 10. Future Coaching Platform

Allow trainers and nutrition professionals to manage clients, assign programs, review nutrition logs, and monitor progress. Initially, the product focuses on individual users.

---

## Supported Platforms

| Platform    | Experience                                            |
|-------------|-------------------------------------------------------|
| Web         | Desktop and responsive mobile browsers; PWA support   |
| Mobile      | Native iOS and Android applications                   |
| Apple Watch | Native watchOS companion app                          |
| Desktop     | Windows, macOS, and Linux applications                |

---

## Technology Stack

| Component       | Technology                                                  |
|-----------------|-------------------------------------------------------------|
| Backend         | Laravel modular monolith, API-only                          |
| Database        | PostgreSQL                                                  |
| Web             | Nuxt + Vue 3 + TypeScript                                   |
| Mobile          | React Native + TypeScript                                   |
| Desktop         | Tauri + shared Vue frontend                                 |
| Apple Watch     | SwiftUI + HealthKit                                         |
| Authentication  | Laravel Sanctum                                             |
| Cache / Queues  | Redis                                                       |
| Storage         | S3-compatible object storage                                |
| API Contract    | OpenAPI-generated clients                                   |
| Architecture    | API-first monorepo; independently deployed applications     |
| AI              | Rules-based recommendation engine plus optional LLM integration |

---

## Proposed Repository Structure

```
fitness-platform/
├── apps/
│   ├── api/          # Laravel
│   ├── web/          # Nuxt responsive web/PWA
│   ├── mobile/       # React Native iOS + Android
│   ├── desktop/      # Tauri
│   └── watch-ios/    # SwiftUI
├── packages/
│   ├── api-client/
│   ├── shared-types/
│   ├── business-rules/
│   └── design-tokens/
└── infrastructure/
    ├── docker/
    └── terraform/
```

---

## Initial MVP

1. User profiles and customizable goals.
2. Admin-provided and user-created food database.
3. Meal logging, serving sizes, and daily macro tracking.
4. Workout and cardio activity logging.
5. Manual health metrics.
6. Daily dashboard and rules-based recommendations.
7. Basic progress charts and weekly reviews.

## Later Phases

- React Native mobile apps and offline synchronization.
- Apple Health integration and Apple Watch companion.
- Tauri desktop app.
- Advanced meal planning, grocery lists, and budgeting.
- AI coaching and adaptive targets.
- Multi-client professional coach features.

---

## Product Vision

A personal fitness operating system that transforms nutrition, exercise, and health data into actionable daily guidance.

The long-term goal is a highly customizable platform that helps users make better fitness decisions, measure progress, and adapt routines based on results.
