<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td style="$InlineStyle;font-family:$EffectiveFont;" align="$safeAlignment">
        <% if $ButtonURL %>
            <a href="$ButtonURL.ATT" target="_blank" style="$ButtonStyle">$Label.XML</a>
        <% end_if %>
    </td>
</tr>
