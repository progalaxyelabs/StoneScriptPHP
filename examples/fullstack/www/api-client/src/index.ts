/**
 * Auto-generated TypeScript API Client
 * Generated from PHP routes
 *
 * DO NOT EDIT MANUALLY - Regenerate using: php generate client
 */

// ============================================================================
// Type Definitions
// ============================================================================

export interface WebsitesRequest {
  name: string;
  type: string;
  userId?: string | null;
}

export interface WebsitesResponse {
  id: string;
  name: string;
  type: string;
  status: string;
  createdAt: string;
}


// ============================================================================
// API Client
// ============================================================================

export const api = {
  async postWebsites(data: WebsitesRequest): Promise<WebsitesResponse> {
    const response = await fetch('/websites', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(data),
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const json = await response.json();
    return json.data;
  },

};

export default api;
