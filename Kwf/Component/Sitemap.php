<?php
class Kwf_Component_Sitemap
{
    public static function output(Kwf_Component_Data $domain)
    {
        header('Content-Type: text/xml; charset=utf-8');
        echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        $stmt = Kwf_Registry::get('db')->query(
            "SELECT url, changed_date, sitemap_changefreq, sitemap_priority
                FROM kwf_pages_meta
                WHERE deleted=0 AND meta_noindex=0 AND domain_component_id=?",
            array($domain->componentId)
        );
        while ($row = $stmt->fetch()) {
            echo "<url>\n";
            echo " <loc>".Kwf_Util_HtmlSpecialChars::filter($row['url'])."</loc>\n";
            echo " <lastmod>".date('c', strtotime($row['changed_date']))."</lastmod>\n";
            echo " <changefreq>".$row['sitemap_changefreq']."</changefreq>\n";
            echo " <priority>".$row['sitemap_priority']."</priority>\n";
            echo "</url>\n";
        }
        echo "</urlset>\n";
        exit;
    }
}
