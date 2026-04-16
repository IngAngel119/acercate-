// AcercateAPIService.swift
// Swift 5.9+ · iOS 16+ · URLSession with async/await
//
// Usage:
//   let api = AcercateAPIService(baseURL: "http://localhost:8000")
//
//   let (user, token) = try await api.register(name: "Ana", email: "ana@example.com", password: "secret123")
//   api.setToken(token)
//
//   let entries = try await api.listJournalEntries()
//   let reflection = try await api.generateWeeklyReflection()

import Foundation

// MARK: - Models

struct AuthResponse: Decodable {
    let user: UserResponse
    let token: String
}

struct UserResponse: Decodable {
    let id: Int
    let name: String
    let email: String
    let record: Int
}

struct JournalEntry: Decodable, Identifiable {
    let id: Int
    let userId: Int
    let content: String
    let entryDate: String
    let createdAt: String?
    let updatedAt: String?

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case content
        case entryDate = "entry_date"
        case createdAt = "created_at"
        case updatedAt = "updated_at"
    }
}

struct Reflection: Decodable, Identifiable {
    let id: Int
    let userId: Int
    let content: String
    let image: String?
    let reflectionDate: String
    let weekStartDate: String?
    let weekEndDate: String?
    let isGenerated: Bool
    let createdAt: String?
    let updatedAt: String?

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case content
        case image
        case reflectionDate = "reflection_date"
        case weekStartDate = "week_start_date"
        case weekEndDate = "week_end_date"
        case isGenerated = "is_generated"
        case createdAt = "created_at"
        case updatedAt = "updated_at"
    }
}

struct Quote: Decodable, Identifiable {
    let id: Int
    let content: String
    let author: String
    let image: String?
}

struct APIError: Decodable, LocalizedError {
    let message: String
    var errorDescription: String? { message }
}

// MARK: - Service

final class AcercateAPIService {

    // Change this to your server URL (e.g. "https://api.acercate.app")
    private let baseURL: String
    private var token: String?

    private let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.dateDecodingStrategy = .iso8601
        return d
    }()

    init(baseURL: String = "http://localhost:8000") {
        self.baseURL = baseURL
    }

    func setToken(_ token: String) {
        self.token = token
    }

    func clearToken() {
        self.token = nil
    }

    // MARK: – Auth

    func register(
        name: String,
        email: String,
        password: String,
        deviceName: String = UIDevice.current.name
    ) async throws -> AuthResponse {
        try await post(
            path: "/api/auth/register",
            body: [
                "name": name,
                "email": email,
                "password": password,
                "password_confirmation": password,
                "device_name": deviceName,
            ]
        )
    }

    func login(
        email: String,
        password: String,
        deviceName: String = UIDevice.current.name
    ) async throws -> AuthResponse {
        try await post(
            path: "/api/auth/login",
            body: [
                "email": email,
                "password": password,
                "device_name": deviceName,
            ]
        )
    }

    func me() async throws -> UserResponse {
        try await get(path: "/api/auth/me")
    }

    func logout() async throws {
        let _: EmptyResponse = try await post(path: "/api/auth/logout", body: [:])
    }

    // MARK: – Journal entries

    func listJournalEntries() async throws -> [JournalEntry] {
        try await get(path: "/api/journal-entries")
    }

    func createJournalEntry(content: String, entryDate: String) async throws -> JournalEntry {
        try await post(
            path: "/api/journal-entries",
            body: ["content": content, "entry_date": entryDate]
        )
    }

    func updateJournalEntry(id: Int, content: String? = nil, entryDate: String? = nil) async throws -> JournalEntry {
        var body: [String: String] = [:]
        if let content { body["content"] = content }
        if let entryDate { body["entry_date"] = entryDate }
        return try await patch(path: "/api/journal-entries/\(id)", body: body)
    }

    func deleteJournalEntry(id: Int) async throws {
        try await delete(path: "/api/journal-entries/\(id)")
    }

    // MARK: – Reflections

    func listReflections() async throws -> [Reflection] {
        try await get(path: "/api/reflections")
    }

    func showReflection(id: Int) async throws -> Reflection {
        try await get(path: "/api/reflections/\(id)")
    }

    /// Generate (or regenerate) the weekly reflection for the given Monday.
    /// Pass nil to generate for the current week.
    func generateWeeklyReflection(weekStartDate: String? = nil, image: String? = nil) async throws -> Reflection {
        var body: [String: String] = [:]
        if let weekStartDate { body["week_start_date"] = weekStartDate }
        if let image { body["image"] = image }
        return try await post(path: "/api/reflections/weekly/generate", body: body)
    }

    /// Fetch the already-generated reflection for the current week, if it exists.
    func currentWeeklyReflection() async throws -> Reflection {
        try await get(path: "/api/reflections/weekly/current")
    }

    func createReflection(content: String, reflectionDate: String, image: String? = nil) async throws -> Reflection {
        var body: [String: String] = ["content": content, "reflection_date": reflectionDate]
        if let image { body["image"] = image }
        return try await post(path: "/api/reflections", body: body)
    }

    // MARK: – Quotes

    func listQuotes() async throws -> [Quote] {
        try await get(path: "/api/quotes")
    }

    // MARK: – User (authenticated)

    func updateUser(id: Int, name: String? = nil, email: String? = nil, record: Int? = nil) async throws -> UserResponse {
        var body: [String: Any] = [:]
        if let name { body["name"] = name }
        if let email { body["email"] = email }
        if let record { body["record"] = record }
        return try await patchAny(path: "/api/users/\(id)", body: body)
    }

    func deleteUser(id: Int) async throws {
        try await delete(path: "/api/users/\(id)")
    }

    // MARK: – Private HTTP primitives

    private func get<T: Decodable>(path: String) async throws -> T {
        let request = try buildRequest(path: path, method: "GET")
        return try await execute(request)
    }

    private func post<B: Encodable, T: Decodable>(path: String, body: B) async throws -> T {
        var request = try buildRequest(path: path, method: "POST")
        request.httpBody = try JSONEncoder().encode(body)
        return try await execute(request)
    }

    private func patch<T: Decodable>(path: String, body: [String: String]) async throws -> T {
        var request = try buildRequest(path: path, method: "PATCH")
        request.httpBody = try JSONSerialization.data(withJSONObject: body)
        return try await execute(request)
    }

    private func patchAny<T: Decodable>(path: String, body: [String: Any]) async throws -> T {
        var request = try buildRequest(path: path, method: "PATCH")
        request.httpBody = try JSONSerialization.data(withJSONObject: body)
        return try await execute(request)
    }

    private func delete(path: String) async throws {
        let request = try buildRequest(path: path, method: "DELETE")
        let (_, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse, (200...299).contains(http.statusCode) else {
            throw URLError(.badServerResponse)
        }
    }

    private func buildRequest(path: String, method: String) throws -> URLRequest {
        guard let url = URL(string: baseURL + path) else {
            throw URLError(.badURL)
        }

        var request = URLRequest(url: url)
        request.httpMethod = method
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")

        if let token {
            request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }

        return request
    }

    private func execute<T: Decodable>(_ request: URLRequest) async throws -> T {
        let (data, response) = try await URLSession.shared.data(for: request)

        guard let http = response as? HTTPURLResponse else {
            throw URLError(.badServerResponse)
        }

        if !(200...299).contains(http.statusCode) {
            // Try to surface the API error message
            if let apiError = try? decoder.decode(APIError.self, from: data) {
                throw apiError
            }
            throw URLError(.badServerResponse)
        }

        return try decoder.decode(T.self, from: data)
    }
}

// Used for responses with no meaningful body (e.g. logout)
private struct EmptyResponse: Decodable {}

// MARK: - Example usage (SwiftUI ViewModel)

/*
@MainActor
final class DiaryViewModel: ObservableObject {
    private let api = AcercateAPIService()

    @Published var entries: [JournalEntry] = []
    @Published var currentReflection: Reflection?
    @Published var error: String?

    func login(email: String, password: String) async {
        do {
            let auth = try await api.login(email: email, password: password)
            api.setToken(auth.token)
            // Persist token: Keychain or @AppStorage
            await loadDashboard()
        } catch {
            self.error = error.localizedDescription
        }
    }

    func loadDashboard() async {
        do {
            async let entriesTask = api.listJournalEntries()
            async let reflectionTask = api.currentWeeklyReflection()

            entries = try await entriesTask

            // 404 means no reflection yet — that's fine
            if let reflection = try? await reflectionTask {
                currentReflection = reflection
            }
        } catch {
            self.error = error.localizedDescription
        }
    }

    func addEntry(content: String) async {
        let today = ISO8601DateFormatter().string(from: Date()).prefix(10)
        do {
            let entry = try await api.createJournalEntry(content: content, entryDate: String(today))
            entries.insert(entry, at: 0)
        } catch {
            self.error = error.localizedDescription
        }
    }

    func generateReflection() async {
        do {
            currentReflection = try await api.generateWeeklyReflection()
        } catch {
            self.error = error.localizedDescription
        }
    }
}
*/
