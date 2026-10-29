<tr<% if $HideOnMobile %> class="hide-mobile"<% end_if %>>
    <td class="$BlockClass" style="$InlineStyle" align="$safeAlignment">
        <% if $LinkURL %><a href="$LinkURL.ATT" target="_blank"><% end_if %>
        <% with $ScaledImage %>
            <img src="$AbsoluteURL" alt="$Up.Alt.ATT" width="$Width" style="display:block;border:0;outline:none;text-decoration:none;max-width:100%;height:auto;<% if $Up.Alignment == center %>margin:0 auto;<% end_if %>" />
        <% end_with %>
        <% if $LinkURL %></a><% end_if %>
        <% if $Caption %><p style="font-family:$EffectiveFont;font-size:13px;line-height:1.5;font-style:italic;color:$EffectiveMutedColor;margin:8px 0 0;">$Caption.XML</p><% end_if %>
    </td>
</tr>
