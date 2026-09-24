    /**
     * Holt alle offenen Bounties für das Admin-Dashboard.
     */
    public function getAllBounties(): array
    {
        $stmt = $this->db->getConnection()->query("
            SELECT t.*, 
                   po.display_name AS origin_name,
                   pc.display_name AS claimed_name
            FROM gamification_tasks t
            LEFT JOIN gamification_profiles po ON t.origin_profile_id = po.id
            LEFT JOIN gamification_profiles pc ON t.claimed_by_profile_id = pc.id
            WHERE t.is_bounty = 1
              AND t.status IN ('planned', 'escalated', 'in_progress')
            ORDER BY t.created_at ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
