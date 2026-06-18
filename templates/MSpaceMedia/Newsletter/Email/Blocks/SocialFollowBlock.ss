<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td style="$InlineStyle;font-family:$EffectiveFont;font-size:14px;" align="$safeAlignment">
        <% loop $Links %>
            <a href="$URL.ATT" target="_blank" style="color:$Up.EffectiveLinkColor;text-decoration:none;margin:0 8px;">$Label</a>
        <% end_loop %>
    </td>
</tr>
