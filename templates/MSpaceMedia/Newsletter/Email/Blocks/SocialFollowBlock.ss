<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td style="$InlineStyle;font-family:$EffectiveFont;font-size:14px;" align="$safeAlignment">
        <% loop $Links %>
            <% if $IconURL %>
                <a href="$URL.ATT" target="_blank" style="text-decoration:none;display:inline-block;margin:0 6px;"><img src="$IconURL.ATT" alt="$Label.ATT" width="$IconSize" height="$IconSize" style="display:inline-block;width:{$IconSize}px;height:{$IconSize}px;border:0;outline:none;text-decoration:none;" /></a>
            <% else %>
                <a href="$URL.ATT" target="_blank" style="color:$Up.EffectiveLinkColor;text-decoration:none;margin:0 8px;">$Label</a>
            <% end_if %>
        <% end_loop %>
    </td>
</tr>
